<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

use OCA\Memories\Settings\SystemConfig;
use OCP\Http\Client\IClientService;

/**
 * Jamendo: a large catalogue of independent music under Creative Commons licences. Needs a
 * free client id (developer.jamendo.com). Tracks are fetched by mood tags, popular first,
 * and one of the best ten is picked at random so videos do not all sound the same.
 */
final class JamendoProvider implements ProviderInterface
{
    private const API = 'https://api.jamendo.com/v3.0/tracks/';

    public function __construct(private IClientService $clients) {}

    #[\Override]
    public function id(): string
    {
        return 'jamendo';
    }

    #[\Override]
    public function configured(): bool
    {
        return '' !== trim((string) SystemConfig::get('memories.music.jamendo_client_id'));
    }

    #[\Override]
    public function find(array $tags, int $minSeconds, int $maxSeconds): ?Track
    {
        $client = $this->clients->newClient();
        $query = [
            'client_id' => trim((string) SystemConfig::get('memories.music.jamendo_client_id')),
            'format' => 'json',
            'limit' => 15,
            'fuzzytags' => implode('+', $tags),
            'audioformat' => 'mp32',
            'include' => 'licenses',
            'order' => 'popularity_month',
            'durationbetween' => max(10, $minSeconds).'_'.max($minSeconds + 30, $maxSeconds),
            // only tracks that may be used in one's own videos
            'ccnd' => 'false',
            'ccnc' => 'false',
        ];
        $response = $client->get(self::API, ['query' => $query, 'timeout' => 20]);
        $data = json_decode((string) $response->getBody(), true);
        $results = \is_array($data) ? ($data['results'] ?? []) : [];
        if (!\is_array($results) || 0 === \count($results)) {
            // no track long enough under a free licence: try without the length
            unset($query['durationbetween']);
            $response = $client->get(self::API, ['query' => $query, 'timeout' => 20]);
            $data = json_decode((string) $response->getBody(), true);
            $results = \is_array($data) ? ($data['results'] ?? []) : [];
        }
        $results = array_values(array_filter(\is_array($results) ? $results : [], static fn ($r) => \is_array($r) && !empty($r['audio'])));
        if (0 === \count($results)) {
            return null;
        }
        $pick = $results[random_int(0, min(9, \count($results) - 1))];

        return new Track(
            'Jamendo',
            (string) $pick['audio'],
            (string) ($pick['name'] ?? 'Untitled'),
            (string) ($pick['artist_name'] ?? ''),
            (string) ($pick['license_ccurl'] ?? 'CC'),
            (int) ($pick['duration'] ?? 0),
        );
    }
}
