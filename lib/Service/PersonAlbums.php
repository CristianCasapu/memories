<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Util;
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
     * The photo order the user picked on the person ("best photos first", "only in the
     * foreground") is kept with the album, so the album opens the same way.
     *
     * @return array{album_id:int, name:string, cluster_id:int, added:int, prominence:bool, subjects:bool}
     */
    public function create(string $uid, int $clusterId, bool $prominence = false, bool $subjects = false): array
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

        $this->setOptions($album->getId(), $prominence, $subjects);

        $added = $this->syncOne($uid, $clusterId, $album->getId());

        return [
            'album_id' => $album->getId(),
            'name' => $album->getTitle(),
            'cluster_id' => $clusterId,
            'added' => $added,
            'prominence' => $prominence,
            'subjects' => $subjects,
        ];
    }

    /**
     * The photo order stored for an album, or null if the album does not follow a person.
     *
     * @return null|array{album_id:int, uid:string, cluster_id:int, prominence:bool, subjects:bool}
     */
    public function getForAlbum(int $albumId): ?array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('uid', 'cluster_id', 'sort_prominence', 'only_subjects')
            ->from('memories_person_albums')
            ->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
        ;
        $row = $query->executeQuery()->fetch();
        if (!$row) {
            return null;
        }

        return [
            'album_id' => $albumId,
            'uid' => (string) $row['uid'],
            'cluster_id' => (int) $row['cluster_id'],
            'prominence' => (bool) $row['sort_prominence'],
            'subjects' => (bool) $row['only_subjects'],
        ];
    }

    /** Remember how the album of a person should be ordered and filtered. */
    public function setOptions(int $albumId, bool $prominence, bool $subjects): void
    {
        $query = $this->db->getQueryBuilder();
        $query->update('memories_person_albums')
            ->set('sort_prominence', $query->createNamedParameter($prominence ? 1 : 0, IQueryBuilder::PARAM_INT))
            ->set('only_subjects', $query->createNamedParameter($subjects ? 1 : 0, IQueryBuilder::PARAM_INT))
            ->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
            ->executeStatement()
        ;
    }

    /**
     * @return null|array{album_id:int, name:string, cluster_id:int, last_sync:int, prominence:bool, subjects:bool}
     */
    public function getForCluster(string $uid, int $clusterId): ?array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('pa.album_id', 'pa.cluster_id', 'pa.last_sync', 'pa.sort_prominence', 'pa.only_subjects', 'a.name')
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

        return [
            'album_id' => (int) $row['album_id'],
            'name' => (string) $row['name'],
            'cluster_id' => (int) $row['cluster_id'],
            'last_sync' => (int) $row['last_sync'],
            'prominence' => (bool) $row['sort_prominence'],
            'subjects' => (bool) $row['only_subjects'],
        ];
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

    private function findClusterByTitle(string $uid, string $title): ?int
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id')->from('recognize_face_clusters')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('title', $query->createNamedParameter($title)))
        ;
        $id = $query->executeQuery()->fetchOne();

        return false === $id ? null : (int) $id;
    }

    private function relink(string $uid, int $oldClusterId, int $newClusterId, int $albumId): void
    {
        $query = $this->db->getQueryBuilder();
        $query->update('memories_person_albums')
            ->set('cluster_id', $query->createNamedParameter($newClusterId, IQueryBuilder::PARAM_INT))
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('cluster_id', $query->createNamedParameter($oldClusterId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();
    }

    private function syncOne(string $uid, int $clusterId, int $albumId): int
    {
        /** @var \OCA\Photos\Album\AlbumMapper $mapper */
        $mapper = \OC::$server->get(\OCA\Photos\Album\AlbumMapper::class);
        $album = $mapper->get($albumId);
        if (null === $album) {
            $this->unlink($uid, $clusterId);

            return 0;
        }

        // Recognize re-creates clusters (re-clustering, backend switch): follow the person by name
        if (null === $this->getCluster($uid, $clusterId)) {
            $byTitle = $this->findClusterByTitle($uid, $album->getTitle());
            if (null === $byTitle) {
                $this->logger->info('Person album "'.$album->getTitle().'": its person no longer exists, leaving the album untouched');

                return 0;
            }
            $this->logger->info('Person album "'.$album->getTitle().'": relinked from cluster #'.$clusterId.' to #'.$byTitle);
            $this->relink($uid, $clusterId, $byTitle, $albumId);
            $clusterId = $byTitle;
        }

        // photos of the person, inside the user's timeline folders only (e.g. /Photos), not in the album yet
        $query = $this->db->getQueryBuilder();
        $query->selectDistinct('d.file_id')
            ->from('recognize_face_detections', 'd')
            ->innerJoin('d', 'filecache', 'f', $query->expr()->eq('f.fileid', 'd.file_id'))
            ->leftJoin('d', 'photos_albums_files', 'paf', $query->expr()->andX(
                $query->expr()->eq('paf.file_id', 'd.file_id'),
                $query->expr()->eq('paf.album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)),
            ))
            ->where($query->expr()->eq('d.user_id', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('d.cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ->andWhere(Util::timelineScope($query, $uid, 'f'))
            ->andWhere($query->expr()->isNull('paf.album_file_id'))
        ;
        $fileIds = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

        // files that ended up in the album but are outside the timeline folders (or no longer show the person): remove them
        $query = $this->db->getQueryBuilder();
        $query->select('paf.file_id')
            ->from('photos_albums_files', 'paf')
            ->leftJoin('paf', 'filecache', 'f', $query->expr()->eq('f.fileid', 'paf.file_id'))
            ->where($query->expr()->eq('paf.album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
        ;
        $inAlbum = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
        if (\count($inAlbum) > 0) {
            $query = $this->db->getQueryBuilder();
            $query->selectDistinct('d.file_id')
                ->from('recognize_face_detections', 'd')
                ->innerJoin('d', 'filecache', 'f', $query->expr()->eq('f.fileid', 'd.file_id'))
                ->where($query->expr()->eq('d.user_id', $query->createNamedParameter($uid)))
                ->andWhere($query->expr()->eq('d.cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
                ->andWhere(Util::timelineScope($query, $uid, 'f'))
            ;
            $allowed = array_flip(array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN)));
            // safety: never empty an album because the person has no photos in scope right now
            if (0 === \count($allowed)) {
                return 0;
            }
            foreach ($inAlbum as $fileId) {
                if (!isset($allowed[$fileId])) {
                    try {
                        $mapper->removeFile($albumId, $fileId);
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

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
