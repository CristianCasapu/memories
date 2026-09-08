<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Db\VideoJob;
use OCA\Memories\Db\VideoJobMapper;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\Music\MusicService;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Makes one video from photos (Google Photos "animation" style): frames from the previews, so
 * HEIC / RAW work; ffmpeg; background music chosen by the mood of the photos; the file saved
 * next to the first photo. Progress and the outcome go into the job row, so the person who
 * asked for it (and the administrator) can see what is happening.
 */
final class VideoMaker
{
    public const MAX_FRAMES = 200;
    public const FRAME_SIZE = 1920;
    public const PREVIEW_SIZE = 1024;

    private ?string $workDir = null;

    public function __construct(
        private VideoJobMapper $jobs,
        private MusicService $music,
        private IRootFolder $rootFolder,
        private IPreview $preview,
        private ITempManager $tempManager,
        private IDBConnection $connection,
        private LoggerInterface $logger,
    ) {}

    /** Run a queued job to the end; the row carries the outcome. */
    public function run(VideoJob $job): VideoJob
    {
        if (VideoJob::QUEUED !== $job->getStatus()) {
            return $job;
        }
        $job->setStatus(VideoJob::RUNNING);
        $job->setStarted(time());
        $this->progress($job, 0, 'Preparing the photos');

        try {
            $this->make($job);
            $job->setStatus(VideoJob::DONE);
            $this->progress($job, 100, 'Done');
        } catch (\Throwable $e) {
            $this->logger->warning('Video job '.$job->getId().' failed: '.$e->getMessage(), ['exception' => $e]);
            $job->setStatus(VideoJob::FAILED);
            $job->setError(mb_substr($e->getMessage(), 0, 2000));
        }
        $job->setFinished(time());
        $this->tempManager->clean();
        if (null !== $this->workDir) {
            self::removeDir($this->workDir);
            $this->workDir = null;
        }

        return $this->jobs->update($job);
    }

    /** A bold font ffmpeg can draw with, or null when the host has none */
    public static function font(): ?string
    {
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Can this process encode on the GPU? off (setting), no_device (the GPU is not visible from here:
     * cron can see it, php-fpm with PrivateDevices cannot), no_encoder (ffmpeg without h264_nvenc), ok.
     */
    public static function gpuStatus(string $ffmpeg): string
    {
        static $hasEncoder = [];
        if (!SystemConfig::get('memories.clips.nvenc')) {
            return 'off';
        }
        if (!@is_readable('/dev/nvidia0') || !@is_readable('/dev/nvidiactl')) {
            return 'no_device';
        }
        if (!isset($hasEncoder[$ffmpeg])) {
            [$stdout] = Util::execSafe2([$ffmpeg, '-hide_banner', '-encoders'], 20000, null, true, false);
            $hasEncoder[$ffmpeg] = str_contains((string) $stdout, 'h264_nvenc');
        }

        return $hasEncoder[$ffmpeg] ? 'ok' : 'no_encoder';
    }

    /** The ffmpeg used for clips: memories.clips.ffmpeg, else the transcoder's */
    public static function ffmpegPath(): string
    {
        $ffmpeg = (string) SystemConfig::get('memories.clips.ffmpeg');
        if ('' === $ffmpeg || !is_executable($ffmpeg)) {
            $ffmpeg = (string) SystemConfig::get('memories.vod.ffmpeg');
        }

        return $ffmpeg;
    }

    /** @return array{int, int} width and height of the clip (1080p or 720p) */
    public static function frameSize(): array
    {
        return 720 === (int) SystemConfig::get('memories.clips.height') ? [1280, 720] : [self::FRAME_SIZE, 1080];
    }

    private function make(VideoJob $job): void
    {
        // clips may use their own ffmpeg (a newer build whose NVENC is stable); otherwise the transcoder's
        $ffmpeg = self::ffmpegPath();
        if ('' === $ffmpeg || !is_executable($ffmpeg)) {
            throw Exceptions::NotEnabled('ffmpeg (Administration › Memories › Video: ffmpeg path)');
        }
        $fileids = array_values(array_unique($job->fileIdList()));
        if (\count($fileids) < 2) {
            throw Exceptions::MissingParameter('at least 2 photos');
        }
        if (\count($fileids) > self::MAX_FRAMES) {
            throw Exceptions::BadRequest('too many photos (max '.self::MAX_FRAMES.')');
        }
        $fps = max(0.25, min(15.0, $job->getFps()));

        // order by capture time
        $query = $this->connection->getQueryBuilder();
        $query->select('fileid')->from('memories')
            ->where($query->expr()->in('fileid', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('datetaken', 'ASC')->addOrderBy('fileid', 'ASC')
        ;
        $ordered = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

        /** @var list<int> $ordered */
        $ordered = array_merge($ordered, array_diff($fileids, $ordered));

        $userFolder = $this->rootFolder->getUserFolder($job->getUid());
        $dir = BinExt::getTmpBase().'/clips/job-'.$job->getId().'-'.bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0770, true) || !is_dir($dir)) {
            throw new \Exception('cannot create the working folder '.$dir);
        }
        $this->workDir = $dir;

        [$width, $height] = self::frameSize();
        $previewSize = $height > 720 ? self::PREVIEW_SIZE : 512;
        $first = null;
        $n = 0;
        $total = \count($ordered);
        foreach ($ordered as $i => $fileId) {
            if ($this->cancelled($job)) {
                throw new \Exception('cancelled');
            }
            $node = $userFolder->getFirstNodeById($fileId);
            if (!$node instanceof File || !str_starts_with($node->getMimeType(), 'image/')) {
                continue;
            }
            $first ??= $node;

            try {
                // a 2048-wide preview: enough for 1080p and light to decode (asking for 1920 would return the 4096 one)
                $img = $this->preview->getPreview($node, $previewSize, $previewSize, false, IPreview::MODE_FILL);
                file_put_contents(\sprintf('%s/frame_%04d.jpg', $dir, $n++), $img->getContent());
            } catch (\Throwable $e) {
                $this->logger->warning('Video job: preview failed for '.$fileId, ['exception' => $e]);
            }
            if (0 === $i % 5) {
                $this->progress($job, (int) (5.0 + 55.0 * (float) ($i + 1) / (float) $total), \sprintf('Preparing the photos (%d of %d)', $i + 1, $total));
            }
        }
        if ($n < 2 || null === $first) {
            throw Exceptions::BadRequest('not enough usable photos');
        }

        $this->progress($job, 62, 'Encoding the video');
        $out = $dir.'/burst.mp4';
        $seconds = (float) $n / $fps;
        $filter = \sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,format=yuv420p', $width, $height, $width, $height);
        $filter .= $this->overlays($job, $dir, $seconds, $height);
        $threads = max(0, min(64, (int) SystemConfig::get('memories.clips.threads')));
        $cpuPreset = (string) SystemConfig::get('memories.clips.cpu_preset');
        if (!\in_array($cpuPreset, ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow'], true)) {
            $cpuPreset = 'veryfast';
        }
        $encode = static function (bool $gpu) use ($ffmpeg, $fps, $dir, $filter, $out, $threads, $cpuPreset): array {
            $codec = $gpu
                ? ['-c:v', 'h264_nvenc', '-preset', 'p4', '-rc', 'vbr', '-cq', '23', '-b:v', '0']
                : ['-c:v', 'libx264', '-preset', $cpuPreset, '-crf', '20'];
            $cmd = array_merge(
                [$ffmpeg, '-y', '-loglevel', 'error'],
                $threads > 0 ? ['-threads', (string) $threads, '-filter_threads', (string) $threads] : [],
                ['-framerate', (string) $fps, '-i', $dir.'/frame_%04d.jpg', '-vf', $filter],
                $codec,
                $threads > 0 ? ['-threads', (string) $threads] : [],
                ['-r', '30', '-movflags', '+faststart', $out],
            );

            return self::exec($cmd, 300);
        };
        $ok = false;
        $code = -1;
        $stderr = '';
        if ($this->gpuEncoder($ffmpeg)) {
            $this->progress($job, 63, 'Encoding the video (GPU)');
            [$code, $stderr] = $encode(true);
            // a crashed encoder (signal) leaves a truncated file behind: only a clean exit counts
            $ok = 0 === $code && is_file($out) && filesize($out) > 100;
            if ($ok) {
                $this->logger->info('Video job '.$job->getId().': encoded with NVENC');
            } else {
                $this->logger->info('Video job '.$job->getId().': NVENC failed (exit '.$code.'), using the CPU: '.$stderr);
                @unlink($out);
            }
        }
        if (!$ok) {
            [$code, $stderr] = $encode(false);
            $ok = 0 === $code && is_file($out) && filesize($out) > 100;
        }
        if (!$ok) {
            throw new \Exception('ffmpeg failed (exit '.$code.'): '.$stderr);
        }

        // background music, chosen by the mood of the photos (or the one asked for)
        if ('none' !== $job->getMusic() && $this->music->enabled()) {
            try {
                $this->progress($job, 78, 'Reading the mood of the photos');
                $chosen = $this->music->mood($job->getMusic(), $ordered);
                $job->setMood($chosen['mood']);
                // the track heard in the preview is the one used; otherwise one is picked now
                $rawTrack = $job->getTrack();
                $preset = Music\Track::fromArray(null !== $rawTrack ? json_decode($rawTrack, true) : null);
                $this->progress($job, 84, null !== $preset ? 'Adding the music' : 'Looking for music: '.Music\Mood::label($chosen['mood']));
                $track = $preset ?? $this->music->pick($chosen['mood'], (int) ceil($seconds));
                if (null !== $track) {
                    $this->progress($job, 90, 'Adding the music: '.$track->credit());
                    $out = $this->music->mux($ffmpeg, $out, $track, $seconds, $chosen['mood']);
                    $job->setTrack(json_encode($track->toArray()) ?: null);
                } else {
                    $this->logger->info('Video job '.$job->getId().': no track found for '.$chosen['mood']);
                }
            } catch (\Throwable $e) {
                // the video is worth more than the music: save it silent, but say so
                $this->logger->warning('Video job '.$job->getId().': music failed, saving without', ['exception' => $e]);
                $job->setStep('Music failed ('.mb_substr($e->getMessage(), 0, 120).'), saved without');
            }
        }
        if ($this->cancelled($job)) {
            throw new \Exception('cancelled');
        }

        $this->progress($job, 96, 'Saving the video');
        $parent = $first->getParent();
        $base = '' !== trim($job->getTitle()) ? trim($job->getTitle()) : pathinfo($first->getName(), PATHINFO_FILENAME).'-video';
        $base = preg_replace('/[\/\\\:*?"<>|]+/', '-', $base) ?? $base;
        $target = $base.'.mp4';
        for ($i = 2; $parent->nodeExists($target); ++$i) {
            $target = "{$base} ({$i}).mp4";
        }
        $file = $parent->newFile($target, fopen($out, 'r'));
        $job->setResultFileid($file->getId());
        $job->setResultName($file->getName());
        $job->setResultFolder((string) $userFolder->getRelativePath($parent->getPath()));
        $job->setPhotos($n);

        // into the timeline right away (the top of the home page, the Videos page), not at the next index run
        try {
            \OC::$server->get(Index::class)->indexFile($file);
        } catch (\Throwable $e) {
            $this->logger->debug('Video job: the new file will be indexed by cron: '.$e->getMessage());
        }
    }

    /**
     * Text over the video: a title card during the first seconds (title, location, caption,
     * the people mentioned) and the text lines spread over the duration. Each text goes through
     * a file, so nothing has to be escaped for the filter.
     */
    private function overlays(VideoJob $job, string $dir, float $seconds, int $height = 1080): string
    {
        $k = (float) $height / 1080.0; // font sizes were tuned for 1080p
        $font = self::font();
        if (null === $font) {
            return '';
        }
        $filters = [];
        $write = static function (string $name, string $text) use ($dir): string {
            $path = $dir.'/'.$name.'.txt';
            file_put_contents($path, $text);

            return $path;
        };
        $draw = static function (string $file, int $size, string $y, string $enable) use ($font): string {
            return \sprintf(
                "drawtext=fontfile='%s':textfile='%s':fontsize=%d:fontcolor=white:borderw=2:bordercolor=black@0.8:x=(w-text_w)/2:y=%s:enable='%s'",
                $font,
                $file,
                $size,
                $y,
                $enable,
            );
        };
        $mentions = array_map(static fn ($m) => '@'.$m['name'], $job->mentionList());
        $title = trim($job->getTitle());
        $sub = implode('  ·  ', array_filter([trim((string) $job->getLocation()), trim((string) $job->getCaption())]));
        $card = \sprintf('%.2f', min(3.5, max(1.5, $seconds * 0.3)));
        if ('' !== $title || '' !== $sub || \count($mentions) > 0) {
            $filters[] = "drawbox=x=0:y=ih*0.62:w=iw:h=ih*0.38:color=black@0.45:t=fill:enable='lt(t,{$card})'";
            if ('' !== $title) {
                $filters[] = $draw($write('title', $title), (int) round(64.0 * $k), 'h*0.66', 'lt(t,'.$card.')');
            }
            if ('' !== $sub) {
                $filters[] = $draw($write('sub', $sub), (int) round(40.0 * $k), 'h*0.66+'.(int) round(90.0 * $k), 'lt(t,'.$card.')');
            }
            if (\count($mentions) > 0) {
                $filters[] = $draw($write('with', implode('  ', $mentions)), (int) round(36.0 * $k), 'h*0.66+'.(int) round(150.0 * $k), 'lt(t,'.$card.')');
            }
        }
        $lines = $job->textList();
        if (\count($lines) > 0) {
            $start = ('' !== $title || '' !== $sub) ? (float) $card : 0.0;
            $slice = max(1.0, ($seconds - $start) / (float) \count($lines));
            foreach ($lines as $i => $line) {
                $from = $start + (float) $i * $slice;
                $to = $i === \count($lines) - 1 ? $seconds + 1.0 : $from + $slice;
                $filters[] = $draw($write('line'.$i, $line), (int) round(48.0 * $k), 'h-'.(int) round(140.0 * $k), \sprintf('between(t,%.2f,%.2f)', $from, $to));
            }
        }

        return \count($filters) > 0 ? ','.implode(',', $filters) : '';
    }

    /** Can this process encode on the NVIDIA GPU? (device reachable and ffmpeg built with NVENC) */
    /**
     * Run a command to completion; the exit code (or 128+signal when it crashed) and its stderr.
     *
     * @param list<string> $cmd
     *
     * @return array{int, string}
     */
    private static function exec(array $cmd, int $timeoutSeconds): array
    {
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            return [-1, 'proc_open failed'];
        }
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $deadline = microtime(true) + (float) $timeoutSeconds;
        while (true) {
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[2]);
                proc_close($proc);

                return [-2, 'timeout after '.$timeoutSeconds.'s '.$stderr];
            }
            usleep(50000);
        }
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        $code = $status['exitcode'];
        if ($status['signaled']) {
            $code = 128 + $status['termsig'];
        }

        return [$code, mb_substr(trim($stderr), 0, 2000)];
    }

    private function gpuEncoder(string $ffmpeg): bool
    {
        return 'ok' === self::gpuStatus($ffmpeg);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function progress(VideoJob $job, int $percent, string $step): void
    {
        $job->setProgress(max(0, min(100, $percent)));
        $job->setStep($step);
        $this->jobs->update($job);
    }

    /** Was the job cancelled from the outside while running? */
    private function cancelled(VideoJob $job): bool
    {
        try {
            return VideoJob::CANCELLED === $this->jobs->find((int) $job->getId())->getStatus();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
