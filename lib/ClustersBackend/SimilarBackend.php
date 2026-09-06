<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

/**
 * Groups of duplicate / near-duplicate photos, computed by the CristianCasapu Recognize fork
 * (perceptual hash + CLIP similarity). Each group is a "cluster"; opening it shows the photos of
 * the group so the extra copies can be selected and deleted.
 */
final class SimilarBackend extends Backend
{
    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
        protected IAppManager $appManager,
    ) {}

    #[\Override]
    public static function appName(): string
    {
        return 'Similar photos';
    }

    #[\Override]
    public static function clusterType(): string
    {
        return 'similar';
    }

    #[\Override]
    public function isEnabled(): bool
    {
        try {
            return $this->appManager->isEnabledForUser('recognize')
                && class_exists(\OCA\Recognize\Service\SimilarPhotos::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    #[\Override]
    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        $group = $this->getGroup((string) $this->request->getParam('similar'));
        $query->andWhere($query->expr()->in('m.fileid', $query->createFunction(implode(',', array_map('intval', $group['files'])))));
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        if ($fileid) {
            throw new \Exception('SimilarBackend: fileid filter not implemented');
        }
        $groups = $this->getGroups();
        if (0 === \count($groups)) {
            return [];
        }

        // which of the group files are visible in the user's timeline (folder scope, permissions)
        $all = [];
        foreach ($groups as $group) {
            foreach ($group['files'] as $fileId) {
                $all[] = (int) $fileId;
            }
        }
        $query = $this->tq->getBuilder();
        $query->select('m.fileid', 'f.etag', 'f.size')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
            ->where($query->expr()->in('m.fileid', $query->createFunction(implode(',', $all))))
        ;
        $query = $this->tq->filterFilecache($query);
        $visible = [];
        foreach ($this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [] as $row) {
            $visible[(int) $row['fileid']] = ['etag' => (string) $row['etag'], 'size' => (int) $row['size']];
        }

        $clusters = [];
        foreach ($groups as $group) {
            $files = array_values(array_filter($group['files'], static fn ($id) => isset($visible[(int) $id])));
            if (\count($files) < 2) {
                continue;
            }
            $cover = (int) $files[0];
            $bytes = 0;
            foreach ($files as $id) {
                $bytes += $visible[(int) $id]['size'];
            }
            $clusters[] = [
                'id' => $group['id'],
                'name' => $group['id'],
                'display_name' => \count($files).' × '.self::humanSize($bytes),
                'count' => \count($files),
                'cover' => $cover,
                'cover_etag' => $visible[$cover]['etag'],
                'files' => array_map('intval', $files),
            ];
        }

        return $clusters;
    }

    #[\Override]
    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['id'];
    }

    #[\Override]
    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        $group = $this->getGroup($name);
        $query = $this->tq->getBuilder();
        $query->select('f.fileid', 'f.etag')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
            ->where($query->expr()->in('m.fileid', $query->createFunction(implode(',', array_map('intval', $group['files'])))))
            ->orderBy('f.size', 'DESC')
        ;
        $query = $this->tq->filterFilecache($query);
        if (null !== $limit && $limit > 0) {
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
        return 0; // covers are not stored for similar-photo groups
    }

    /**
     * @return list<array{id:string, files:list<int>, size:int}>
     */
    private function getGroups(): array
    {
        if (!$this->isEnabled()) {
            throw Exceptions::NotEnabled('Recognize (similar photos)');
        }

        return \OC::$server->get(\OCA\Recognize\Service\SimilarPhotos::class)->groups();
    }

    /**
     * @return array{id:string, files:list<int>, size:int}
     */
    private function getGroup(string $id): array
    {
        foreach ($this->getGroups() as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }

        throw Exceptions::NotFound('similar photos group');
    }

    private static function humanSize(int $bytes): string
    {
        return \OCP\Util::humanFileSize($bytes);
    }
}
