<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

use Psr\Log\LoggerInterface;

/**
 * Which mood do these photos have? Every mood is described by a few sentences; the CLIP
 * model of the Recognize fork says how well each sentence fits each photo. The mood whose
 * best sentence fits the photos best, on average, wins. Without CLIP the video gets "calm".
 */
final class MoodDetector
{
    public const FALLBACK = 'calm';

    public function __construct(private LoggerInterface $logger) {}

    public function available(): bool
    {
        if (!class_exists(\OCA\Recognize\Service\SemanticSearch::class)) {
            return false;
        }

        try {
            return \OC::$server->get(\OCA\Recognize\Service\SemanticSearch::class)->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param list<int> $fileIds
     *
     * @return array{mood:string, scores:array<string,float>, detected:bool}
     */
    public function detect(array $fileIds): array
    {
        $fileIds = array_slice(array_values(array_unique(array_map('intval', $fileIds))), 0, 200);
        if (!$this->available() || 0 === \count($fileIds)) {
            return ['mood' => self::FALLBACK, 'scores' => [], 'detected' => false];
        }
        $prompts = [];
        $owner = [];
        foreach (Mood::MOODS as $id => $mood) {
            foreach ($mood['prompts'] as $prompt) {
                $owner[] = $id;
                $prompts[] = $prompt;
            }
        }

        try {
            $search = \OC::$server->get(\OCA\Recognize\Service\SemanticSearch::class);
            $matrix = $search->scoreFiles($fileIds, $prompts);
        } catch (\Throwable $e) {
            $this->logger->warning('Music mood: CLIP scoring failed', ['exception' => $e]);

            return ['mood' => self::FALLBACK, 'scores' => [], 'detected' => false];
        }

        // per photo: the best sentence of each mood; per mood: the mean over the photos
        $sum = array_fill_keys(Mood::ids(), 0.0);
        $seen = [];
        $perPhoto = [];
        foreach ($matrix as $t => $perFile) {
            foreach ($perFile as $fileId => $score) {
                $seen[$fileId] = true;
                $mood = $owner[$t] ?? MoodDetector::FALLBACK;
                $key = $fileId.'|'.$mood;
                $perPhoto[$key] = max($perPhoto[$key] ?? -1.0, (float) $score);
            }
        }
        $count = \count($seen);
        if (0 === $count) {
            return ['mood' => self::FALLBACK, 'scores' => [], 'detected' => false];
        }
        foreach ($perPhoto as $key => $score) {
            $mood = substr($key, (int) strpos($key, '|') + 1);
            $sum[$mood] = ($sum[$mood] ?? 0.0) + $score;
        }
        $scores = [];
        foreach ($sum as $mood => $total) {
            $scores[$mood] = round($total / (float) $count, 4);
        }
        arsort($scores);
        $mood = (string) array_key_first($scores);
        $this->logger->debug('Music mood: '.$mood.' for '.$count.' photos '.(json_encode(array_slice($scores, 0, 4, true)) ?: ''));

        return ['mood' => $mood, 'scores' => $scores, 'detected' => true];
    }
}
