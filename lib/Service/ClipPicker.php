<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Which photos go into a clip when the person does not pick them one by one: all of them,
 * a random handful, or the best ones — photos whose faces are large, sharp and well lit
 * (the prominence score of the Recognize fork), spread over the whole span of time so the
 * clip tells the story from beginning to end.
 */
final class ClipPicker
{
    public const MAX = 200;

    public function __construct(private IDBConnection $db) {}

    /**
     * @param list<int> $fileIds candidates
     * @param string    $mode    all | best | random
     *
     * @return list<int> in capture order
     */
    public function pick(array $fileIds, string $mode, int $n): array
    {
        $unique = [];
        foreach ($fileIds as $id) {
            $unique[(int) $id] = true;
        }

        /** @var list<int> $fileIds */
        $fileIds = array_keys($unique);
        $n = max(2, min(self::MAX, $n));
        if (\count($fileIds) <= $n || 'all' === $mode) {
            return $this->inCaptureOrder(self::head($fileIds, self::MAX));
        }
        if ('random' === $mode) {
            shuffle($fileIds);

            return $this->inCaptureOrder(self::head($fileIds, $n));
        }

        // best: score each photo, then take the best of every time slice
        $scores = $this->prominence($fileIds);
        $ordered = $this->inCaptureOrder($fileIds);
        $slices = max(1, (int) ceil(\count($ordered) / $n));
        $chosen = [];
        foreach (array_chunk($ordered, $slices) as $chunk) {
            usort($chunk, static fn ($a, $b) => ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0));
            $chosen[] = $chunk[0];
        }
        // the slices may leave us short of n: fill up with the best of the rest
        if (\count($chosen) < $n) {
            $rest = array_values(array_diff($ordered, $chosen));
            usort($rest, static fn ($a, $b) => ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0));
            $chosen = array_merge($chosen, \array_slice($rest, 0, $n - \count($chosen)));
        }

        return $this->inCaptureOrder(self::head($chosen, $n));
    }

    /**
     * All photos of an album the user can see.
     *
     * @return list<int>
     */
    public function albumPhotos(string $albumUser, string $albumName): array
    {
        $uid = Util::getUID();
        $query = $this->db->getQueryBuilder();
        $query->select('paf.file_id')
            ->from('photos_albums', 'pa')
            ->innerJoin('pa', 'photos_albums_files', 'paf', $query->expr()->eq('paf.album_id', 'pa.album_id'))
            ->innerJoin('paf', 'memories', 'm', $query->expr()->eq('m.fileid', 'paf.file_id'))
            ->where($query->expr()->eq('pa.name', $query->createNamedParameter($albumName)))
            ->andWhere($query->expr()->eq('pa.user', $query->createNamedParameter($albumUser)))
            ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
        ;
        if ($albumUser !== $uid) {
            // a shared album: the person must be a collaborator
            $query->innerJoin('pa', 'photos_albums_collabs', 'pac', $query->expr()->andX(
                $query->expr()->eq('pac.album_id', 'pa.album_id'),
                $query->expr()->eq('pac.collaborator_id', $query->createNamedParameter($uid)),
            ));
        }

        return array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The photos of one of the user's automatic events.
     *
     * @return list<int>
     */
    public function eventPhotos(int $eventId): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('mef.fileid')
            ->from('memories_events', 'ev')
            ->innerJoin('ev', 'memories_events_files', 'mef', $query->expr()->eq('mef.event_id', 'ev.id'))
            ->innerJoin('mef', 'memories', 'm', $query->expr()->eq('m.fileid', 'mef.fileid'))
            ->where($query->expr()->eq('ev.id', $query->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('ev.uid', $query->createNamedParameter(Util::getUID())))
            ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
        ;

        return array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<array-key, int> $ids
     *
     * @return list<int>
     */
    private static function head(array $ids, int $n): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (\count($out) >= $n) {
                break;
            }
            $out[] = (int) $id;
        }

        return $out;
    }

    /**
     * @param list<int> $fileIds
     *
     * @return list<int>
     */
    private function inCaptureOrder(array $fileIds): array
    {
        if (0 === \count($fileIds)) {
            return [];
        }
        $taken = [];
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $query = $this->db->getQueryBuilder();
            $query->select('fileid', 'datetaken')->from('memories')
                ->where($query->expr()->in('fileid', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
            ;
            foreach ($query->executeQuery()->fetchAll() as $row) {
                $taken[(int) $row['fileid']] = (string) $row['datetaken'];
            }
        }
        asort($taken);
        $out = [];
        foreach (array_keys($taken) as $id) {
            $out[] = (int) $id;
        }
        foreach ($fileIds as $id) {
            if (!isset($taken[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * The most prominent face of every photo (0 when the photo has no scored face).
     *
     * @param list<int> $fileIds
     *
     * @return array<int, float>
     */
    private function prominence(array $fileIds): array
    {
        $scores = [];

        try {
            foreach (array_chunk($fileIds, 500) as $chunk) {
                $query = $this->db->getQueryBuilder();
                $query->select('file_id')
                    ->selectAlias($query->func()->max('quality'), 'q')
                    ->from('recognize_face_detections')
                    ->where($query->expr()->in('file_id', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->groupBy('file_id')
                ;
                foreach ($query->executeQuery()->fetchAll() as $row) {
                    $scores[(int) $row['file_id']] = (float) $row['q'];
                }
            }
        } catch (\Throwable $e) {
            // no Recognize fork: every photo is as good as any other
        }

        return $scores;
    }
}
