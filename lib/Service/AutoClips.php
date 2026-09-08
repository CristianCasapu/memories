<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Clips nobody asked for: for every person who switched it on, each automatic event with
 * enough photos gets a clip of its best photos, with music chosen by the mood. One clip
 * per event, made once the event is at least a day old (so it is complete).
 */
final class AutoClips
{
    public const MIN_PHOTOS = 8;
    public const PHOTOS_PER_CLIP = 24;
    public const SECONDS_PER_PHOTO = 1.5;
    public const MAX_PER_RUN = 3;

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private IUserConfig $userConfig,
        private VideoJobs $jobs,
        private ClipPicker $picker,
        private LoggerInterface $logger,
    ) {}

    public function enabledFor(string $uid): bool
    {
        return 'true' === $this->userConfig->getValueString($uid, Application::APPNAME, 'autoClips', 'false');
    }

    /** @return int clips queued */
    public function runAll(): int
    {
        $n = 0;
        $this->userManager->callForSeenUsers(function ($user) use (&$n): void {
            try {
                if ($this->enabledFor($user->getUID())) {
                    $n += $this->runForUser($user->getUID());
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Auto clips failed for '.$user->getUID(), ['exception' => $e]);
            }
        });

        return $n;
    }

    /** @return int clips queued */
    public function runForUser(string $uid, int $max = self::MAX_PER_RUN): int
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'title', 'place', 'start', 'end', 'count')->from('memories_events')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->gte('count', $query->createNamedParameter(self::MIN_PHOTOS, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->lt('end', $query->createNamedParameter(time() - 86400, IQueryBuilder::PARAM_INT)))
            ->orderBy('start', 'DESC')->setMaxResults(200)
        ;
        $made = 0;
        foreach ($query->executeQuery()->fetchAll() as $event) {
            $source = ['event' => (int) $event['id']];
            if ($this->jobs->existsForSource($uid, 'auto', $source)) {
                continue;
            }
            $fileIds = $this->eventPhotos($uid, (int) $event['id']);
            if (\count($fileIds) < self::MIN_PHOTOS) {
                continue;
            }
            $picked = $this->picker->pick($fileIds, 'best', self::PHOTOS_PER_CLIP);
            $title = trim((string) $event['title']);
            if ('' === $title) {
                $title = trim((string) $event['place']);
            }
            $title = trim($title.' '.date('j M Y', (int) $event['start']));
            $this->jobs->create($uid, $picked, 1.0 / self::SECONDS_PER_PHOTO, 'auto', $title, [
                'kind' => 'auto',
                'source' => $source,
                'location' => (string) $event['place'],
            ]);
            $this->logger->info('Auto clip queued for '.$uid.': '.$title.' ('.\count($picked).' photos)');
            if (++$made >= $max) {
                break;
            }
        }

        return $made;
    }

    /** @return list<int> */
    private function eventPhotos(string $uid, int $eventId): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('mef.fileid')
            ->from('memories_events', 'ev')
            ->innerJoin('ev', 'memories_events_files', 'mef', $query->expr()->eq('mef.event_id', 'ev.id'))
            ->innerJoin('mef', 'memories', 'm', $query->expr()->eq('m.fileid', 'mef.fileid'))
            ->where($query->expr()->eq('ev.id', $query->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('ev.uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
        ;

        return array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
    }
}
