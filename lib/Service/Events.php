<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Automatic events ("Wedding · 1–2 Aug 2026", "Sinaia · 14–16 Aug 2026"): the user's photos
 * sorted by capture time are cut into sessions whenever there is a long pause or a big jump in
 * location. An event is titled after its dominant place (reverse geocoding) or, without GPS,
 * after the folder most of its photos live in.
 */
final class Events
{
    /** a pause longer than this starts a new event */
    public const MAX_GAP = 4 * 3600;
    /** a jump farther than this (km) from the running centroid starts a new event */
    public const MAX_DISTANCE_KM = 15.0;
    public const MIN_PHOTOS = 8;

    public function __construct(
        private IDBConnection $db,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {}

    public function rebuildAll(): int
    {
        $events = 0;
        $this->userManager->callForSeenUsers(function ($user) use (&$events): void {
            try {
                $events += $this->rebuild($user->getUID());
            } catch (\Throwable $e) {
                $this->logger->warning('Events rebuild failed for '.$user->getUID(), ['exception' => $e]);
            }
        });

        return $events;
    }

    /**
     * Recompute the events of one user from scratch.
     *
     * @return int number of events
     */
    public function rebuild(string $uid): int
    {
        $storageId = $this->getHomeStorageId($uid);
        if (null === $storageId) {
            return 0;
        }

        // all indexed photos/videos of the user's home, oldest first
        $query = $this->db->getQueryBuilder();
        $query->select('m.fileid', 'm.datetaken', 'm.lat', 'm.lon', 'f.path')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
            ->where($query->expr()->eq('f.storage', $query->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->like('f.path', $query->createNamedParameter('files/%')))
            ->andWhere($query->expr()->eq('m.orphan', $query->expr()->literal(0, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.datetaken', 'ASC')
        ;
        $rows = $query->executeQuery()->fetchAll();

        $events = [];
        $current = null;
        foreach ($rows as $row) {
            $time = strtotime((string) $row['datetaken']) ?: 0;
            $lat = null !== $row['lat'] ? (float) $row['lat'] : null;
            $lon = null !== $row['lon'] ? (float) $row['lon'] : null;
            $folder = self::folderOf((string) $row['path']);

            $newEvent = null === $current
                || $time - $current['end'] > self::MAX_GAP
                || (null !== $lat && null !== $current['lat'] && self::distanceKm($lat, $lon, $current['lat'], $current['lon']) > self::MAX_DISTANCE_KM);
            if ($newEvent) {
                if (null !== $current) {
                    $events[] = $current;
                }
                $current = ['start' => $time, 'end' => $time, 'files' => [], 'lat' => null, 'lon' => null, 'gps' => 0, 'folders' => []];
            }
            $current['end'] = max($current['end'], $time);
            $current['files'][] = (int) $row['fileid'];
            $current['folders'][$folder] = ($current['folders'][$folder] ?? 0) + 1;
            if (null !== $lat && null !== $lon) {
                // running centroid
                $current['lat'] = (($current['lat'] ?? $lat) * $current['gps'] + $lat) / ($current['gps'] + 1);
                $current['lon'] = (($current['lon'] ?? $lon) * $current['gps'] + $lon) / ($current['gps'] + 1);
                ++$current['gps'];
            }
        }
        if (null !== $current) {
            $events[] = $current;
        }
        $events = array_values(array_filter($events, static fn ($e) => \count($e['files']) >= self::MIN_PHOTOS));

        // replace the stored events of this user
        $this->db->beginTransaction();

        try {
            $del = $this->db->getQueryBuilder();
            $del->delete('memories_events_files')->where($del->expr()->in('event_id', $del->createFunction(
                '(SELECT id FROM *PREFIX*memories_events WHERE uid = '.$del->createNamedParameter($uid).')'
            )))->executeStatement();
            $del = $this->db->getQueryBuilder();
            $del->delete('memories_events')->where($del->expr()->eq('uid', $del->createNamedParameter($uid)))->executeStatement();

            foreach ($events as $event) {
                $place = $this->dominantPlace($event['files']);
                arsort($event['folders']);
                $folder = (string) array_key_first($event['folders']);
                $ins = $this->db->getQueryBuilder();
                $ins->insert('memories_events')->values([
                    'uid' => $ins->createNamedParameter($uid),
                    'start' => $ins->createNamedParameter($event['start'], IQueryBuilder::PARAM_INT),
                    'end' => $ins->createNamedParameter($event['end'], IQueryBuilder::PARAM_INT),
                    'title' => $ins->createNamedParameter(mb_substr('' !== $place ? $place : $folder, 0, 255)),
                    'place' => $ins->createNamedParameter(mb_substr($place, 0, 255)),
                    'count' => $ins->createNamedParameter(\count($event['files']), IQueryBuilder::PARAM_INT),
                    'cover' => $ins->createNamedParameter($event['files'][(int) (\count($event['files']) / 2)], IQueryBuilder::PARAM_INT),
                    'updated' => $ins->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
                ])->executeStatement();
                $eventId = $ins->getLastInsertId();
                foreach (array_chunk($event['files'], 500) as $chunk) {
                    foreach ($chunk as $fileId) {
                        $f = $this->db->getQueryBuilder();
                        $f->insert('memories_events_files')->values([
                            'event_id' => $f->createNamedParameter($eventId, IQueryBuilder::PARAM_INT),
                            'fileid' => $f->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        ])->executeStatement();
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return \count($events);
    }

    /**
     * Most frequent city/town-level place name among the files (reverse geocoding data), or ''.
     *
     * @param list<int> $fileIds
     */
    private function dominantPlace(array $fileIds): string
    {
        $best = '';
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $query = $this->db->getQueryBuilder();
            $query->select('p.name')
                ->selectAlias($query->func()->count('mp.fileid'), 'n')
                ->from('memories_places', 'mp')
                ->innerJoin('mp', 'memories_planet', 'p', $query->expr()->eq('p.osm_id', 'mp.osm_id'))
                ->where($query->expr()->in('mp.fileid', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($query->expr()->gte('p.admin_level', $query->expr()->literal(6, IQueryBuilder::PARAM_INT)))
                ->andWhere($query->expr()->lte('p.admin_level', $query->expr()->literal(8, IQueryBuilder::PARAM_INT)))
                ->groupBy('p.name')
                ->orderBy('n', 'DESC')
                ->setMaxResults(1)
            ;
            $row = $query->executeQuery()->fetch();
            if ($row && '' === $best) {
                $best = (string) $row['name'];
            }
        }

        return $best;
    }

    private function getHomeStorageId(string $uid): ?int
    {
        $query = $this->db->getQueryBuilder();
        $query->select('numeric_id')->from('storages')->where($query->expr()->eq('id', $query->createNamedParameter('home::'.$uid)));
        $id = $query->executeQuery()->fetchOne();

        return false === $id ? null : (int) $id;
    }

    /** Name of the folder a file lives in, e.g. "Petrecere" for files/Photos/Petrecere/x.jpg */
    private static function folderOf(string $path): string
    {
        $parts = explode('/', $path);
        array_pop($parts); // file name
        array_shift($parts); // "files"

        return '' !== ($last = (string) end($parts)) ? $last : 'Photos';
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
