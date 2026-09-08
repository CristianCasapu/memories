<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

use OCA\Memories\Settings\SystemConfig;
use OCP\Http\Client\IClientService;

/**
 * Freesound: a collaborative database of sounds, loops and short musical pieces (CC0 and
 * CC-BY only are used). Needs an API token (freesound.org/apiv2/apply). The high-quality
 * MP3 preview is what a token grants without OAuth; it is enough for a background track.
 */
final class FreesoundProvider implements ProviderInterface
{
    private const API = 'https://freesound.org/apiv2/search/text/';

    public function __construct(private IClientService $clients) {}

    #[\Override]
    public function id(): string
    {
        return 'freesound';
    }

    #[\Override]
    public function configured(): bool
    {
        return '' !== trim((string) SystemConfig::get('memories.music.freesound_token'));
    }

    #[\Override]
    public function find(array $tags, int $minSeconds, int $maxSeconds): ?Track
    {
        $client = $this->clients->newClient();
        $query = [
            'query' => implode(' ', $tags).' music',
            'filter' => \sprintf('duration:[%d TO %d] license:("Creative Commons 0" OR "Attribution")', max(10, $minSeconds), max($minSeconds + 30, $maxSeconds)),
            'fields' => 'id,name,username,license,previews,duration',
            'sort' => 'rating_desc',
            'page_size' => 15,
            'token' => trim((string) SystemConfig::get('memories.music.freesound_token')),
        ];
        $response = $client->get(self::API, ['query' => $query, 'timeout' => 20]);
        $data = json_decode((string) $response->getBody(), true);
        /** @var list<array<string, mixed>> $results */
        $results = [];
        if (\is_array($data) && \is_array($data['results'] ?? null)) {
            foreach ($data['results'] as $r) {
                if (\is_array($r) && !empty($r['previews']['preview-hq-mp3'])) {
                    $results[] = $r;
                }
            }
        }
        if (0 === \count($results)) {
            return null;
        }
        $pick = $results[random_int(0, min(9, \count($results) - 1))];

        return new Track(
            'Freesound',
            (string) $pick['previews']['preview-hq-mp3'],
            (string) ($pick['name'] ?? 'Untitled'),
            (string) ($pick['username'] ?? ''),
            (string) ($pick['license'] ?? 'CC'),
            (int) round((float) ($pick['duration'] ?? 0)),
        );
    }
}
