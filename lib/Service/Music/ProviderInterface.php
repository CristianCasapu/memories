<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

interface ProviderInterface
{
    public function id(): string;

    /** Are the keys this provider needs configured? */
    public function configured(): bool;

    /**
     * A track for the mood, at least $minSeconds long when possible.
     *
     * @param list<string> $tags provider-neutral mood words ("party", "happy", "piano" …)
     *
     * @throws \RuntimeException when the provider answers with an error
     */
    public function find(array $tags, int $minSeconds, int $maxSeconds): ?Track;
}
