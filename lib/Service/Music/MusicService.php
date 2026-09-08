<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

use OCA\Memories\Settings\SystemConfig;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Background music for the videos made from photos: the mood comes from the pictures (or is
 * chosen), a provider gives a track, ffmpeg lays it under the video with a fade-out and the
 * credit goes into the file's metadata. Music never makes a video fail: when nothing can be
 * found, the video is saved silent.
 */
final class MusicService
{
    public function __construct(
        private MoodDetector $moods,
        private JamendoProvider $jamendo,
        private FreesoundProvider $freesound,
        private MubertProvider $mubert,
        private IClientService $clients,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
    ) {}

    public function enabled(): bool
    {
        return (bool) SystemConfig::get('memories.music.enabled') && \count($this->providers()) > 0;
    }

    /** @return list<ProviderInterface> in the administrator's order, configured ones only */
    public function providers(): array
    {
        $all = ['jamendo' => $this->jamendo, 'freesound' => $this->freesound, 'mubert' => $this->mubert];
        $order = array_values(array_filter(array_map('trim', explode(',', (string) SystemConfig::get('memories.music.providers')))));
        $out = [];
        foreach ($order as $id) {
            if (isset($all[$id]) && $all[$id]->configured()) {
                $out[] = $all[$id];
            }
        }

        return $out;
    }

    /** @return array{enabled:bool, providers:list<string>, moodDetection:bool, moods:array<string,string>} */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled(),
            'providers' => array_map(static fn (ProviderInterface $p) => $p->id(), $this->providers()),
            'moodDetection' => $this->moods->available(),
            'moods' => array_map(static fn ($m) => $m['label'], Mood::MOODS),
        ];
    }

    /**
     * Decide the mood: the one asked for, or the one the photos suggest.
     *
     * @param list<int> $fileIds
     *
     * @return array{mood:string, detected:bool}
     */
    public function mood(string $wanted, array $fileIds): array
    {
        if (Mood::exists($wanted)) {
            return ['mood' => $wanted, 'detected' => false];
        }
        $found = $this->moods->detect($fileIds);

        return ['mood' => $found['mood'], 'detected' => $found['detected']];
    }

    /** A track for the mood from the first provider that has one. */
    public function pick(string $mood, int $seconds): ?Track
    {
        $spec = Mood::MOODS[$mood] ?? Mood::MOODS[MoodDetector::FALLBACK];
        foreach ($this->providers() as $provider) {
            $tags = match ($provider->id()) {
                'freesound' => explode(' ', $spec['freesound']),
                'mubert' => $spec['mubert'],
                default => $spec['tags'],
            };

            try {
                $track = $provider->find($tags, $seconds, $seconds * 4 + 120);
                if (null !== $track) {
                    return $track;
                }
                $this->logger->info('Music: '.$provider->id().' has nothing for "'.$mood.'"');
            } catch (\Throwable $e) {
                $this->logger->warning('Music: '.$provider->id().' failed: '.$e->getMessage());
            }
        }

        return null;
    }

    /**
     * Lay the track under the video: looped when shorter, cut when longer, 1 s fade in and
     * 3 s fade out, credit in the metadata. Returns the path of the new file.
     *
     * @throws \RuntimeException when ffmpeg fails
     */
    public function mux(string $ffmpeg, string $videoPath, Track $track, float $seconds, string $mood): string
    {
        $audio = $this->tempManager->getTemporaryFile('.mp3');
        if (false === $audio) {
            throw new \RuntimeException('no temporary file');
        }
        $this->clients->newClient()->get($track->url, ['sink' => $audio, 'timeout' => 120]);
        if (!is_file($audio) || filesize($audio) < 1000) {
            throw new \RuntimeException('the track could not be downloaded');
        }
        $out = \dirname($videoPath).'/with-music.mp4';
        $volume = max(0.1, min(1.0, (float) SystemConfig::get('memories.music.volume')));
        $fadeOutStart = max(0.0, $seconds - 3.0);
        $filter = \sprintf('volume=%.2f,afade=t=in:st=0:d=1,afade=t=out:st=%.2f:d=3', $volume, $fadeOutStart);
        $cmd = [
            $ffmpeg, '-y', '-loglevel', 'error',
            '-i', $videoPath, '-stream_loop', '-1', '-i', $audio,
            '-map', '0:v:0', '-map', '1:a:0', '-shortest',
            '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-af', $filter,
            '-metadata', 'comment=Music: '.$track->credit().' | mood: '.Mood::label($mood),
            '-movflags', '+faststart', $out,
        ];
        [, $stderr] = \OCA\Memories\Util::execSafe2($cmd, 180000, null, false, true);
        @unlink($audio);
        if (!is_file($out) || filesize($out) < 100) {
            throw new \RuntimeException('ffmpeg failed: '.trim((string) $stderr));
        }

        return $out;
    }
}
