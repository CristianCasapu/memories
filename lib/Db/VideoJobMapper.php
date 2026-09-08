<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<VideoJob> */
final class VideoJobMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'memories_video_jobs', VideoJob::class);
    }

    public function find(int $id): VideoJob
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
        ;

        return $this->findEntity($qb);
    }

    /** @return list<VideoJob> newest first */
    public function findForUser(string $uid, int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->orderBy('created', 'DESC')->setMaxResults($limit)
        ;

        return $this->findEntities($qb);
    }

    /** @return list<VideoJob> newest first, every user */
    public function findAll(int $limit = 200): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->orderBy('created', 'DESC')->setMaxResults($limit)
        ;

        return $this->findEntities($qb);
    }

    /** @return list<VideoJob> oldest first */
    public function findByStatus(string $status, int $limit = 20): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
            ->orderBy('created', 'ASC')->setMaxResults($limit)
        ;

        return $this->findEntities($qb);
    }
}
