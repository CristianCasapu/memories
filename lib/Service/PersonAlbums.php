<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * "Album of this person": a regular (shareable) Photos album that is kept in sync with the
 * photos in which a recognized person appears. New photos of the person are added by the
 * PersonAlbumsJob; photos removed from the album by hand are added again on the next sync
 * (the album is a compilation of the person, not a hand-picked selection).
 */
final class PersonAlbums
{
    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {}

    public static function isAvailable(): bool
    {
        return class_exists(\OCA\Photos\Album\AlbumMapper::class);
    }

    /**
     * Create (or reuse) the album for a Recognize cluster of the user and link it.
     *
     * @return array{album_id:int, name:string, cluster_id:int, added:int}
     */
    public function create(string $uid, int $clusterId): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('The Photos app (albums) is not available');
        }
        $cluster = $this->getCluster($uid, $clusterId);
        if (null === $cluster) {
            throw \OCA\Memories\Exceptions::NotFound('person');
        }

        $existing = $this->getForCluster($uid, $clusterId);
        /** @var \OCA\Photos\Album\AlbumMapper $mapper */
        $mapper = \OC::$server->get(\OCA\Photos\Album\AlbumMapper::class);
        $album = null;
        if (null !== $existing) {
            $album = $mapper->get((int) $existing['album_id']);
        }
        if (null === $album) {
            $name = '' !== $cluster['title'] ? $cluster['title'] : 'Person '.$clusterId;
            $album = $mapper->getByName($name, $uid) ?? $mapper->create($uid, $name);
            $this->link($uid, $clusterId, $album->getId());
        }

        $added = $this->syncOne($uid, $clusterId, $album->getId());

        return ['album_id' => $album->getId(), 'name' => $album->getTitle(), 'cluster_id' => $clusterId, 'added' => $added];
    }

    /**
     * @return null|array{album_id:int, name:string, cluster_id:int, last_sync:int}
     */
    public function getForCluster(string $uid, int $clusterId): ?array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('pa.album_id', 'pa.cluster_id', 'pa.last_sync', 'a.name')
            ->from('memories_person_albums', 'pa')
            ->leftJoin('pa', 'photos_albums', 'a', $query->expr()->eq('a.album_id', 'pa.album_id'))
            ->where($query->expr()->eq('pa.uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('pa.cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
        ;
        $row = $query->executeQuery()->fetch();
        if (!$row) {
            return null;
        }
        if (null === $row['name']) {
            // album was deleted: forget the link
            $this->unlink($uid, $clusterId);

            return null;
        }

        return ['album_id' => (int) $row['album_id'], 'name' => (string) $row['name'], 'cluster_id' => (int) $row['cluster_id'], 'last_sync' => (int) $row['last_sync']];
    }

    public function unlink(string $uid, int $clusterId): void
    {
        $query = $this->db->getQueryBuilder();
        $query->delete('memories_person_albums')
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ->executeStatement()
        ;
    }

    /**
     * Add the missing photos of every linked person to its album.
     *
     * @return int number of photos added
     */
    public function syncAll(): int
    {
        if (!self::isAvailable()) {
            return 0;
        }
        $query = $this->db->getQueryBuilder();
        $query->select('uid', 'cluster_id', 'album_id')->from('memories_person_albums');
        $links = $query->executeQuery()->fetchAll();
        $added = 0;
        foreach ($links as $link) {
            try {
                $added += $this->syncOne((string) $link['uid'], (int) $link['cluster_id'], (int) $link['album_id']);
            } catch (\Throwable $e) {
                $this->logger->warning('Person album sync failed for album '.$link['album_id'], ['exception' => $e]);
            }
        }

        return $added;
    }

    private function syncOne(string $uid, int $clusterId, int $albumId): int
    {
        /** @var \OCA\Photos\Album\AlbumMapper $mapper */
        $mapper = \OC::$server->get(\OCA\Photos\Album\AlbumMapper::class);
        if (null === $mapper->get($albumId)) {
            $this->unlink($uid, $clusterId);

            return 0;
        }

        // photos of the person that are not in the album yet
        $query = $this->db->getQueryBuilder();
        $query->selectDistinct('d.file_id')
            ->from('recognize_face_detections', 'd')
            ->leftJoin('d', 'photos_albums_files', 'paf', $query->expr()->andX(
                $query->expr()->eq('paf.file_id', 'd.file_id'),
                $query->expr()->eq('paf.album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)),
            ))
            ->where($query->expr()->eq('d.user_id', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('d.cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->isNull('paf.album_file_id'))
        ;
        $fileIds = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

        $added = 0;
        foreach ($fileIds as $fileId) {
            try {
                $mapper->addFile($albumId, $fileId, $uid);
                ++$added;
            } catch (\Throwable $e) {
                // e.g. the file was deleted meanwhile
            }
        }

        $query = $this->db->getQueryBuilder();
        $query->update('memories_person_albums')
            ->set('last_sync', $query->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
            ->executeStatement()
        ;

        return $added;
    }

    private function link(string $uid, int $clusterId, int $albumId): void
    {
        $this->unlink($uid, $clusterId);
        $query = $this->db->getQueryBuilder();
        $query->insert('memories_person_albums')->values([
            'uid' => $query->createNamedParameter($uid),
            'album_id' => $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT),
            'backend' => $query->createNamedParameter('recognize'),
            'cluster_id' => $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT),
            'created' => $query->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'last_sync' => $query->createNamedParameter(0, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /**
     * @return null|array{id:int, title:string}
     */
    private function getCluster(string $uid, int $clusterId): ?array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'title')
            ->from('recognize_face_clusters')
            ->where($query->expr()->eq('id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($uid)))
        ;
        $row = $query->executeQuery()->fetch();

        return $row ? ['id' => (int) $row['id'], 'title' => (string) $row['title']] : null;
    }
}
