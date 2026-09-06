<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDateTimeFormatter;
use OCP\IRequest;

/**
 * Automatic events (see Service\Events), shown like albums: cover, "Place · date range", count.
 */
final class EventsBackend extends Backend
{
    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
        protected IDateTimeFormatter $dateTimeFormatter,
    ) {}

    #[\Override]
    public static function appName(): string
    {
        return 'Events';
    }

    #[\Override]
    public static function clusterType(): string
    {
        return 'events';
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return Util::isLoggedIn();
    }

    #[\Override]
    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        $eventId = (int) $this->request->getParam('events');
        $query->innerJoin('m', 'memories_events_files', 'mef', $query->expr()->andX(
            $query->expr()->eq('mef.fileid', 'm.fileid'),
            $query->expr()->eq('mef.event_id', $query->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)),
        ));
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        $uid = Util::getUID();
        $query = $this->tq->getBuilder();
        $count = $query->func()->count(\OCA\Memories\Db\SQL::distinct($query, 'm.fileid'), 'count');
        $query->select('ev.id', 'ev.title', 'ev.place', 'ev.start', 'ev.end', 'ev.cover', $count)
            ->from('memories_events', 'ev')
            ->innerJoin('ev', 'memories_events_files', 'mef', $query->expr()->eq('mef.event_id', 'ev.id'))
            ->innerJoin('mef', 'memories', 'm', $query->expr()->eq('m.fileid', 'mef.fileid'))
            ->where($query->expr()->eq('ev.uid', $query->createNamedParameter($uid)))
        ;
        if ($fileid) {
            $query->andWhere($query->expr()->eq('mef.fileid', $query->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)));
        }
        // only photos in the user's timeline
        $query = $this->tq->filterFilecache($query);
        $query->addGroupBy('ev.id')->addOrderBy('ev.start', 'DESC');

        $query = \OCA\Memories\Db\SQL::materialize($query, 'ev');
        $this->tq->selectEtag($query, 'ev.cover', 'cover_etag');

        $rows = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['count'] = (int) $row['count'];
            $row['name'] = (string) $row['id'];
            $row['display_name'] = $this->displayName((string) $row['title'], (int) $row['start'], (int) $row['end']);
            $row['cover'] = (int) $row['cover'];
        }

        return $rows;
    }

    #[\Override]
    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['id'];
    }

    #[\Override]
    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        $query = $this->tq->getBuilder();
        $query->select('f.fileid', 'f.etag', 'mef.event_id')
            ->from('memories_events_files', 'mef')
            ->where($query->expr()->eq('mef.event_id', $query->createNamedParameter((int) $name, IQueryBuilder::PARAM_INT)))
        ;
        $query->innerJoin('mef', 'memories', 'm', $query->expr()->eq('m.fileid', 'mef.fileid'));
        $query = $this->tq->filterFilecache($query);
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'));
        if (-6 === $limit) {
            $query->innerJoin('mef', 'memories_events', 'ev', $query->expr()->andX(
                $query->expr()->eq('ev.id', 'mef.event_id'),
                $query->expr()->eq('ev.cover', 'f.fileid'),
            ));
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('f.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    #[\Override]
    public function getClusterIdFrom(array $photo): int
    {
        return (int) $photo['event_id'];
    }

    private function displayName(string $title, int $start, int $end): string
    {
        $startDay = date('Y-m-d', $start);
        $endDay = date('Y-m-d', $end);
        if ($startDay === $endDay) {
            $range = $this->dateTimeFormatter->formatDate($start, 'long');
        } elseif (date('Y-m', $start) === date('Y-m', $end)) {
            $range = date('j', $start).'–'.$this->dateTimeFormatter->formatDate($end, 'long');
        } else {
            $range = $this->dateTimeFormatter->formatDate($start, 'medium').' – '.$this->dateTimeFormatter->formatDate($end, 'medium');
        }

        return ('' !== $title ? $title.' · ' : '').$range;
    }
}
