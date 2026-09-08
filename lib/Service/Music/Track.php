<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

/** One piece of music a provider offers for a video. */
final class Track
{
    public function __construct(
        public readonly string $provider,
        public readonly string $url,
        public readonly string $title,
        public readonly string $artist,
        public readonly string $license,
        public readonly int $seconds,
    ) {}

    /** "Title — Artist (license, via Provider)" for the video's metadata and the toast */
    public function credit(): string
    {
        $title = trim($this->title.('' !== $this->artist ? ' — '.$this->artist : ''));
        $extra = array_filter([$this->license, 'via '.$this->provider]);

        return $title.' ('.implode(', ', $extra).')';
    }

    public function toArray(): array
    {
        return ['provider' => $this->provider, 'title' => $this->title, 'artist' => $this->artist, 'license' => $this->license, 'seconds' => $this->seconds, 'credit' => $this->credit()];
    }
}
