<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Music;

use OCA\Memories\Settings\SystemConfig;
use OCP\Http\Client\IClientService;

/**
 * Mubert: royalty-free music generated on demand for a given length and mood, so it never
 * triggers copyright claims. Needs a paid B2B licence token ("pat"). The track is rendered
 * by Mubert and downloaded when ready (usually a few seconds).
 */
final class MubertProvider implements ProviderInterface
{
    private const API = 'https://api-b2b.mubert.com/v2/RecordTrackTTM';
    private const WAIT_SECONDS = 60;

    public function __construct(private IClientService $clients) {}

    #[\Override]
    public function id(): string
    {
        return 'mubert';
    }

    #[\Override]
    public function configured(): bool
    {
        return '' !== trim((string) SystemConfig::get('memories.music.mubert_token'));
    }

    #[\Override]
    public function find(array $tags, int $minSeconds, int $maxSeconds): ?Track
    {
        $client = $this->clients->newClient();
        $body = [
            'method' => 'RecordTrackTTM',
            'params' => [
                'pat' => trim((string) SystemConfig::get('memories.music.mubert_token')),
                'duration' => max(30, min(600, $minSeconds + 5)),
                'tags' => $tags,
                'mode' => 'track',
                'format' => 'mp3',
                'bitrate' => 128,
                'intensity' => 'medium',
            ],
        ];
        $response = $client->post(self::API, ['json' => $body, 'timeout' => 30]);
        $data = json_decode((string) $response->getBody(), true);
        if (!\is_array($data) || ($data['status'] ?? 0) !== 1) {
            throw new \RuntimeException('Mubert: '.(string) ($data['error']['text'] ?? 'unexpected answer'));
        }
        $task = $data['data']['tasks'][0] ?? null;
        $url = \is_array($task) ? (string) ($task['download_link'] ?? '') : '';
        if ('' === $url) {
            throw new \RuntimeException('Mubert: no download link');
        }
        // the file appears when rendering is done
        $deadline = time() + self::WAIT_SECONDS;
        while (time() < $deadline) {
            try {
                $head = $client->head($url, ['timeout' => 10]);
                if (200 === $head->getStatusCode()) {
                    break;
                }
            } catch (\Throwable $e) {
            }
            sleep(3);
        }

        return new Track('Mubert', $url, 'Generated track ('.implode(', ', $tags).')', 'Mubert AI', 'Mubert licence', $body['params']['duration']);
    }
}
