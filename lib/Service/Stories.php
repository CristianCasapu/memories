<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Stories: a handful of photos shown one after the other, full screen, the way a phone app shows
 * them. Some are put together by the person out of a selection; the rest the server proposes by
 * itself — this day in an earlier year, the week that just passed, an event, a person.
 *
 * A story is only a list of photos with a title: nothing is copied, nothing is encoded. The photos
 * are picked with the same scoring as the clips, so the faces that are large, sharp and in focus
 * come first.
 */
final class Stories
{
    /** How many photos one story holds. */
    public const MAX_PHOTOS = 24;

    /** An automatic story that nobody watched is dropped after this many days. */
    public const KEEP_DAYS = 30;

    /** How far back "this day" looks. */
    public const MAX_YEARS = 15;

    /** The fewest photos worth a story. */
    public const MIN_PHOTOS = 4;

    public function __construct(
        private IDBConnection $db,
        private ClipPicker $picker,
        private IL10N $l10n,
    ) {}

    /**
     * Every story of a user, the automatic ones refreshed first, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $uid, bool $refresh = true): array
    {
        if ($refresh) {
            $this->refresh($uid);
        }

        $query = $this->db->getQueryBuilder();
        $query->select('s.id', 's.title', 's.subtitle', 's.auto_key', 's.cover', 's.created', 's.seen', 'fc.etag')
            ->selectAlias($query->func()->count('sf.fileid'), 'count')
            ->from('memories_stories', 's')
            ->leftJoin('s', 'memories_stories_files', 'sf', $query->expr()->eq('sf.story_id', 's.id'))
            ->leftJoin('s', 'filecache', 'fc', $query->expr()->eq('fc.fileid', 's.cover'))
            ->where($query->expr()->eq('s.uid', $query->createNamedParameter($uid)))
            ->groupBy('s.id', 's.title', 's.subtitle', 's.auto_key', 's.cover', 's.created', 's.seen', 'fc.etag')
            ->orderBy('s.created', 'DESC')
        ;

        $out = [];
        foreach ($query->executeQuery()->fetchAll() as $row) {
            $count = (int) $row['count'];
            if (0 === $count) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'kind' => explode(':', (string) $row['auto_key'])[0] ?: 'manual',
                'cover' => (int) $row['cover'],
                'cover_etag' => (string) ($row['etag'] ?? ''),
                'created' => (int) $row['created'],
                'seen' => (int) $row['seen'],
                'count' => $count,
            ];
        }

        return $out;
    }

    /**
     * The photos of a story, in the order they are shown.
     *
     * @return list<array<string, mixed>>
     */
    public function photos(string $uid, int $id): array
    {
        $this->own($uid, $id);

        $query = $this->db->getQueryBuilder();
        $query->select('m.fileid', 'm.dayid', 'm.datetaken', 'm.w', 'm.h', 'f.etag', 'f.name AS basename', 'sf.ordering')
            ->from('memories_stories_files', 'sf')
            ->innerJoin('sf', 'memories', 'm', $query->expr()->eq('m.fileid', 'sf.fileid'))
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
            ->where($query->expr()->eq('sf.story_id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere(Util::timelineScope($query, $uid, 'f'))
            ->orderBy('sf.ordering', 'ASC')
        ;

        $out = [];
        foreach ($query->executeQuery()->fetchAll() as $row) {
            $out[] = [
                'fileid' => (int) $row['fileid'],
                'dayid' => (int) $row['dayid'],
                'etag' => (string) $row['etag'],
                'basename' => (string) $row['basename'],
                'w' => (int) $row['w'],
                'h' => (int) $row['h'],
                'datetaken' => (string) $row['datetaken'],
                'flag' => 0,
            ];
        }

        return $out;
    }

    /**
     * Put a story together out of a list of photos.
     *
     * @param list<int> $fileIds
     */
    public function create(string $uid, string $title, array $fileIds, string $subtitle = '', string $autoKey = '', string $mode = 'best'): int
    {
        $fileIds = $this->picker->pick($this->mine($uid, $fileIds), $mode, self::MAX_PHOTOS);
        if (\count($fileIds) < 1) {
            throw new \InvalidArgumentException('no photos for the story');
        }

        $query = $this->db->getQueryBuilder();
        $query->insert('memories_stories')->values([
            'uid' => $query->createNamedParameter($uid),
            'title' => $query->createNamedParameter(mb_substr(trim($title), 0, 255)),
            'subtitle' => $query->createNamedParameter(mb_substr(trim($subtitle), 0, 255)),
            'auto_key' => $query->createNamedParameter(mb_substr($autoKey, 0, 128)),
            'cover' => $query->createNamedParameter($fileIds[0], IQueryBuilder::PARAM_INT),
            'created' => $query->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'seen' => $query->createNamedParameter(0, IQueryBuilder::PARAM_INT),
        ]);
        $query->executeStatement();
        $id = $this->db->lastInsertId('memories_stories');

        $order = 0;
        foreach ($fileIds as $fileId) {
            $ins = $this->db->getQueryBuilder();
            $ins->insert('memories_stories_files')->values([
                'story_id' => $ins->createNamedParameter($id, IQueryBuilder::PARAM_INT),
                'fileid' => $ins->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                'ordering' => $ins->createNamedParameter($order++, IQueryBuilder::PARAM_INT),
            ]);
            $ins->executeStatement();
        }

        return $id;
    }

    public function rename(string $uid, int $id, string $title): void
    {
        $this->own($uid, $id);
        $query = $this->db->getQueryBuilder();
        $query->update('memories_stories')
            ->set('title', $query->createNamedParameter(mb_substr(trim($title), 0, 255)))
            ->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();
    }

    public function delete(string $uid, int $id): void
    {
        $this->own($uid, $id);
        foreach (['memories_stories_files' => 'story_id', 'memories_stories' => 'id'] as $table => $column) {
            $query = $this->db->getQueryBuilder();
            $query->delete($table)->where($query->expr()->eq($column, $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $query->executeStatement();
        }
    }

    /** Remember where the person stopped watching (0 = never opened, -1 = watched to the end). */
    public function markSeen(string $uid, int $id, int $fileId): void
    {
        $this->own($uid, $id);
        $query = $this->db->getQueryBuilder();
        $query->update('memories_stories')
            ->set('seen', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
            ->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();
    }

    /**
     * Make the automatic stories of a user that do not exist yet, and drop the stale ones.
     *
     * @return int how many were made
     */
    public function refresh(string $uid): int
    {
        $this->prune($uid);
        $have = $this->autoKeys($uid);
        $made = 0;

        foreach ($this->proposals($uid) as $key => $story) {
            if (isset($have[$key])) {
                continue;
            }

            try {
                $this->create($uid, $story['title'], $story['fileids'], $story['subtitle'] ?? '', $key);
                ++$made;
            } catch (\Throwable $e) {
                // one story that cannot be made must not stop the rest
            }
        }

        return $made;
    }

    /**
     * What the server would like to show today.
     *
     * @return array<string, array{title: string, subtitle?: string, fileids: list<int>}>
     */
    private function proposals(string $uid): array
    {
        $out = [];

        // this day, in the years before
        $today = new \DateTimeImmutable('today');
        for ($yearsAgo = 1; $yearsAgo <= self::MAX_YEARS; ++$yearsAgo) {
            $then = $today->modify("-{$yearsAgo} years");
            $dayId = (int) floor($then->getTimestamp() / 86400);
            $ids = $this->photosBetween($uid, $dayId, $dayId);
            if (\count($ids) < self::MIN_PHOTOS) {
                continue;
            }
            $out["thisday:{$then->format('Y-m-d')}"] = [
                'title' => $this->l10n->n('%n year ago', '%n years ago', $yearsAgo),
                'subtitle' => $then->format('j M Y'),
                'fileids' => $ids,
            ];
        }

        // the week that just passed
        $weekStart = (new \DateTimeImmutable('today'))->modify('monday last week');
        $ids = $this->photosBetween(
            $uid,
            (int) floor($weekStart->getTimestamp() / 86400),
            (int) floor($weekStart->modify('+6 days')->getTimestamp() / 86400),
        );
        if (\count($ids) >= self::MIN_PHOTOS) {
            $out['week:'.$weekStart->format('o-W')] = [
                'title' => $this->l10n->t('Last week'),
                'subtitle' => $weekStart->format('j M').' – '.$weekStart->modify('+6 days')->format('j M Y'),
                'fileids' => $ids,
            ];
        }

        foreach ($this->recentEvents($uid) as $key => $event) {
            $out[$key] = $event;
        }

        foreach ($this->recentPeople($uid) as $key => $person) {
            $out[$key] = $person;
        }

        return $out;
    }

    /**
     * Events of the last three months that are big enough to be worth a story.
     *
     * @return array<string, array{title: string, subtitle: string, fileids: list<int>}>
     */
    private function recentEvents(string $uid): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'title', 'place', 'start', 'end', 'count')
            ->from('memories_events')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->gte('end', $query->createNamedParameter(time() - 92 * 86400, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->gte('count', $query->createNamedParameter(self::MIN_PHOTOS * 2, IQueryBuilder::PARAM_INT)))
            ->orderBy('end', 'DESC')
            ->setMaxResults(5)
        ;

        $out = [];

        foreach ($query->executeQuery()->fetchAll() as $row) {
            $ids = $this->eventPhotos($uid, (int) $row['id']);
            if (\count($ids) < self::MIN_PHOTOS) {
                continue;
            }
            $title = trim((string) $row['title']) ?: date('j M Y', (int) $row['start']);
            $out['event:'.(int) $row['id']] = [
                'title' => $title,
                'subtitle' => trim((string) $row['place']) ?: date('j M Y', (int) $row['start']),
                'fileids' => $ids,
            ];
        }

        return $out;
    }

    /**
     * People (from the Recognize fork) who show up often in the photos of the last two months.
     *
     * @return array<string, array{title: string, subtitle: string, fileids: list<int>}>
     */
    private function recentPeople(string $uid): array
    {
        $since = (int) floor((time() - 61 * 86400) / 86400);
        $month = date('Y-m');

        try {
            $query = $this->db->getQueryBuilder();
            $query->select('rfc.id', 'rfc.title')
                ->selectAlias($query->func()->count('m.fileid'), 'count')
                ->from('recognize_face_clusters', 'rfc')
                ->innerJoin('rfc', 'recognize_face_detections', 'rfd', $query->expr()->eq('rfd.cluster_id', 'rfc.id'))
                ->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'))
                ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
                ->where($query->expr()->eq('rfc.user_id', $query->createNamedParameter($uid)))
                ->andWhere($query->expr()->neq('rfc.title', $query->createNamedParameter('')))
                ->andWhere($query->expr()->gte('m.dayid', $query->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
                ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->andWhere(Util::timelineScope($query, $uid, 'f'))
                ->groupBy('rfc.id', 'rfc.title')
                ->orderBy('count', 'DESC')
                ->setMaxResults(3)
            ;
            $clusters = $query->executeQuery()->fetchAll();
        } catch (\Throwable $e) {
            return []; // no Recognize
        }

        $out = [];

        foreach ($clusters as $row) {
            if ((int) $row['count'] < self::MIN_PHOTOS * 2) {
                continue;
            }
            $ids = $this->personPhotos($uid, (int) $row['id'], $since);
            if (\count($ids) < self::MIN_PHOTOS) {
                continue;
            }
            $out['person:'.(int) $row['id'].':'.$month] = [
                'title' => (string) $row['title'],
                'subtitle' => $this->l10n->t('Recently'),
                'fileids' => $ids,
            ];
        }

        return $out;
    }

    /**
     * Photos of the user's timeline taken between two days (days since the epoch).
     *
     * @return list<int>
     */
    private function photosBetween(string $uid, int $dayStart, int $dayEnd): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('m.fileid')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
            ->where(Util::timelineScope($query, $uid, 'f'))
            ->andWhere($query->expr()->gte('m.dayid', $query->createNamedParameter($dayStart, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->lte('m.dayid', $query->createNamedParameter($dayEnd, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(ClipPicker::MAX)
        ;

        return array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
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
            ->setMaxResults(ClipPicker::MAX)
        ;

        return array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function personPhotos(string $uid, int $clusterId, int $sinceDay): array
    {
        $query = $this->db->getQueryBuilder();
        $query->selectDistinct('m.fileid')
            ->from('recognize_face_detections', 'rfd')
            ->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'))
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
            ->where($query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            // the photos taken of this person, not the ones they walked into
            ->andWhere($query->expr()->orX(
                $query->expr()->isNull('rfd.subject'),
                $query->expr()->gte('rfd.subject', $query->createNamedParameter(\OCA\Memories\ClustersBackend\RecognizeBackend::subjectThreshold())),
            ))
            ->andWhere($query->expr()->gte('m.dayid', $query->createNamedParameter($sinceDay, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere(Util::timelineScope($query, $uid, 'f'))
            ->setMaxResults(ClipPicker::MAX)
        ;

        return array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Keep only the photos that are really in this user's timeline.
     *
     * @param list<int> $fileIds
     *
     * @return list<int>
     */
    private function mine(string $uid, array $fileIds): array
    {
        if (0 === \count($fileIds)) {
            return [];
        }

        $out = [];
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $query = $this->db->getQueryBuilder();
            $query->select('m.fileid')
                ->from('memories', 'm')
                ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
                ->where($query->expr()->in('m.fileid', $query->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->andWhere(Util::timelineScope($query, $uid, 'f'))
            ;
            foreach ($query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /** @return array<string, true> */
    private function autoKeys(string $uid): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('auto_key')->from('memories_stories')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->neq('auto_key', $query->createNamedParameter('')))
        ;

        return array_fill_keys(array_map('strval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN)), true);
    }

    /** Automatic stories that had their time; the ones made by hand stay. */
    private function prune(string $uid): void
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id')->from('memories_stories')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->neq('auto_key', $query->createNamedParameter('')))
            ->andWhere($query->expr()->lt('created', $query->createNamedParameter(time() - self::KEEP_DAYS * 86400, IQueryBuilder::PARAM_INT)))
        ;

        foreach ($query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->delete($uid, (int) $id);
        }
    }

    /** Refuse a story that is not this user's. */
    private function own(string $uid, int $id): void
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id')->from('memories_stories')
            ->where($query->expr()->eq('id', $query->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('uid', $query->createNamedParameter($uid)))
        ;
        if (false === $query->executeQuery()->fetchOne()) {
            throw \OCA\Memories\Exceptions::NotFound('story');
        }
    }
}
