<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCP\App\IAppManager;

/**
 * Bridge to the natural-language photo search of the CristianCasapu Recognize fork
 * (\OCA\Recognize\Service\SemanticSearch). Everything is optional: without the fork or its
 * CLIP model the feature simply reports itself as unavailable.
 */
final class SemanticSearchBridge
{
    /** Maximum number of matches handed to the timeline query */
    public const LIMIT = 2000;

    public static function isAvailable(): bool
    {
        try {
            if (!\OC::$server->get(IAppManager::class)->isEnabledForUser('recognize')) {
                return false;
            }
            if (!class_exists(\OCA\Recognize\Service\SemanticSearch::class)) {
                return false;
            }

            return \OC::$server->get(\OCA\Recognize\Service\SemanticSearch::class)->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return null|list<int> matching file ids (best first), null when the feature is not available
     */
    public static function fileIds(string $text): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        /** @var \OCA\Recognize\Service\SemanticSearch $search */
        $search = \OC::$server->get(\OCA\Recognize\Service\SemanticSearch::class);

        return array_keys($search->search($text, self::LIMIT));
    }
}
