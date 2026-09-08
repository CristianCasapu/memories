<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Cron\VideoJobRunner;
use OCA\Memories\Db\VideoJob;
use OCA\Memories\Db\VideoJobMapper;
use OCA\Memories\Notification\Notifier;
use OCA\Memories\Settings\SystemConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * The queue of videos to make. A request puts a row in the table and a background job in
 * Nextcloud's job list; when the server allows it, a worker is started right away so nobody
 * waits for the next cron run. The person is notified when the video is ready or failed.
 */
final class VideoJobs
{
    /** a job "running" longer than this is considered dead */
    public const STALE_SECONDS = 1800;

    public function __construct(
        private VideoJobMapper $mapper,
        private VideoMaker $maker,
        private IJobList $jobList,
        private IManager $notifications,
        private \OCP\Share\IManager $shares,
        private \OCP\Files\IRootFolder $rootFolder,
        private \OCP\IUserManager $userManager,
        private \OCP\IDBConnection $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<int>            $fileIds
     * @param array<string, mixed> $options caption, location, mentions, texts, kind, source, track
     */
    public function create(string $uid, array $fileIds, float $fps, string $music, string $title = '', array $options = []): VideoJob
    {
        $job = new VideoJob();
        $job->setUid($uid);
        $job->setStatus(VideoJob::QUEUED);
        $job->setTitle(mb_substr(trim($title), 0, 200));
        $job->setFileIds(json_encode(array_values(array_unique(array_map('intval', $fileIds)))) ?: '[]');
        $job->setPhotos(\count($fileIds));
        $job->setFps($fps);
        $job->setMusic($music);
        $job->setStep('Waiting to start');
        $job->setCreated(time());
        $job->setCaption(mb_substr(trim((string) ($options['caption'] ?? '')), 0, 1000));
        $job->setLocation(mb_substr(trim((string) ($options['location'] ?? '')), 0, 250));
        $mentions = [];
        $rawMentions = $options['mentions'] ?? null;
        foreach (\is_array($rawMentions) ? $rawMentions : [] as $m) {
            if (\is_string($m)) {
                // a plain name: a user id when one exists, otherwise just a name on the card
                $m = ['uid' => null !== $this->userManager->get($m) ? $m : '', 'name' => $m];
            }
            if (\is_array($m) && '' !== trim((string) ($m['name'] ?? ''))) {
                $mentions[] = ['uid' => mb_substr((string) ($m['uid'] ?? ''), 0, 64), 'name' => mb_substr(trim((string) $m['name']), 0, 100)];
            }
            if (\count($mentions) >= 20) {
                break;
            }
        }
        $job->setMentions(\count($mentions) ? (json_encode($mentions) ?: null) : null);
        $rawTexts = $options['texts'] ?? null;
        $texts = array_values(array_filter(array_map(static fn ($t) => mb_substr(trim((string) $t), 0, 200), \is_array($rawTexts) ? $rawTexts : []), static fn ($t) => '' !== $t));
        $job->setTexts(\count($texts) ? (json_encode(\array_slice($texts, 0, 60)) ?: null) : null);
        $kind = (string) ($options['kind'] ?? 'manual');
        $job->setKind(\in_array($kind, ['manual', 'album', 'event', 'auto'], true) ? $kind : 'manual');
        $rawSource = $options['source'] ?? null;
        $job->setSource(\is_array($rawSource) ? (json_encode($rawSource) ?: null) : null);
        $rawTrack = $options['track'] ?? null;
        if (\is_array($rawTrack) && '' !== (string) ($rawTrack['url'] ?? '')) {
            $job->setTrack(json_encode($rawTrack) ?: null);
        }
        $job = $this->mapper->insert($job);
        $this->schedule($job);

        return $job;
    }

    /** The result files of these jobs, with what the Memories viewer needs to show them. */
    public function decorate(array $jobs): array
    {
        $ids = array_values(array_filter(array_map(static fn ($j) => (int) ($j['result_fileid'] ?? 0), $jobs)));
        $files = [];
        if (\count($ids) > 0) {
            $query = $this->db->getQueryBuilder();
            $query->select('f.fileid', 'f.etag', 'f.mtime', 'f.size', 'm.dayid', 'm.w', 'm.h')
                ->from('filecache', 'f')
                ->leftJoin('f', 'memories', 'm', $query->expr()->eq('m.fileid', 'f.fileid'))
                ->where($query->expr()->in('f.fileid', $query->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ;
            foreach ($query->executeQuery()->fetchAll() as $row) {
                $files[(int) $row['fileid']] = [
                    'etag' => (string) $row['etag'],
                    'dayid' => null !== $row['dayid'] ? (int) $row['dayid'] : (int) floor(((int) $row['mtime']) / 86400),
                    'size' => (int) $row['size'],
                    'w' => (int) ($row['w'] ?? 0),
                    'h' => (int) ($row['h'] ?? 0),
                ];
            }
        }
        foreach ($jobs as &$job) {
            $f = $files[(int) ($job['result_fileid'] ?? 0)] ?? null;
            $job['result_etag'] = $f['etag'] ?? '';
            $job['result_dayid'] = $f['dayid'] ?? 0;
            $job['result_size'] = $f['size'] ?? 0;
            $job['result_w'] = $f['w'] ?? 0;
            $job['result_h'] = $f['h'] ?? 0;
            $job['result_exists'] = null !== $f;
        }

        return $jobs;
    }

    /** Run one job now (worker / cron). */
    public function run(int $id): ?VideoJob
    {
        try {
            $job = $this->mapper->find($id);
        } catch (DoesNotExistException $e) {
            return null;
        }
        if (VideoJob::QUEUED !== $job->getStatus()) {
            return $job;
        }
        $job = $this->maker->run($job);
        $this->notify($job);

        return $job;
    }

    /** Cron: dead jobs are marked failed, waiting jobs are made (a few per run). */
    public function sweep(int $max = 2): int
    {
        $limit = time() - self::STALE_SECONDS;
        foreach ($this->mapper->findByStatus(VideoJob::RUNNING, 50) as $job) {
            if ($job->getStarted() > 0 && $job->getStarted() < $limit) {
                $job->setStatus(VideoJob::FAILED);
                $job->setError('The worker stopped before the video was finished.');
                $job->setFinished(time());
                $this->mapper->update($job);
                $this->notify($job);
            }
        }
        $done = 0;
        foreach ($this->mapper->findByStatus(VideoJob::QUEUED, $max) as $job) {
            $this->run((int) $job->getId());
            ++$done;
        }

        return $done;
    }

    /** @return array<int, array> */
    public function listForUser(string $uid): array
    {
        return $this->decorate(array_map(static fn (VideoJob $j) => $j->toArray(), $this->mapper->findForUser($uid, 200)));
    }

    /** @return array<int, array> */
    public function listAll(): array
    {
        return $this->decorate(array_map(static fn (VideoJob $j) => $j->toArray(), $this->mapper->findAll()));
    }

    /** Has this source (album / event) got a clip already? */
    public function existsForSource(string $uid, string $kind, array $source): bool
    {
        $needle = json_encode($source) ?: '';
        foreach ($this->mapper->findForUser($uid, 500) as $job) {
            if ($job->getKind() === $kind && $job->getSource() === $needle && VideoJob::FAILED !== $job->getStatus() && VideoJob::CANCELLED !== $job->getStatus()) {
                return true;
            }
        }

        return false;
    }

    /** Remove the clip: the row and, when asked, the video file. */
    public function remove(VideoJob $job, bool $withFile): void
    {
        if ($withFile && null !== $job->getResultFileid()) {
            try {
                $folder = $this->rootFolder->getUserFolder($job->getUid());
                $node = $folder->getFirstNodeById((int) $job->getResultFileid());
                $node?->delete();
            } catch (\Throwable $e) {
                $this->logger->warning('Clip: the file could not be removed', ['exception' => $e]);
            }
        }
        $this->mapper->delete($job);
    }

    public function get(int $id): ?VideoJob
    {
        try {
            return $this->mapper->find($id);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function cancel(VideoJob $job): VideoJob
    {
        if (\in_array($job->getStatus(), [VideoJob::QUEUED, VideoJob::RUNNING], true)) {
            $job->setStatus(VideoJob::CANCELLED);
            $job->setFinished(time());
            $job->setStep('Cancelled');
            $job = $this->mapper->update($job);
        }

        return $job;
    }

    public function retry(VideoJob $job): VideoJob
    {
        if (\in_array($job->getStatus(), [VideoJob::FAILED, VideoJob::CANCELLED], true)) {
            $job->setStatus(VideoJob::QUEUED);
            $job->setError(null);
            $job->setProgress(0);
            $job->setStep('Waiting to start');
            $job->setStarted(0);
            $job->setFinished(0);
            $job = $this->mapper->update($job);
            $this->schedule($job);
        }

        return $job;
    }

    public function delete(VideoJob $job): void
    {
        $this->mapper->delete($job);
    }

    /**
     * The people tagged in the clip get to see it: the file is shared with them (read only)
     * and they are told about it.
     */
    private function shareWithMentioned(VideoJob $job): void
    {
        $fileId = (int) $job->getResultFileid();
        if ($fileId <= 0) {
            return;
        }
        foreach ($job->mentionList() as $mention) {
            $uid = $mention['uid'];
            if ('' === $uid || $uid === $job->getUid() || null === $this->userManager->get($uid)) {
                continue;
            }

            try {
                $node = $this->rootFolder->getUserFolder($job->getUid())->getFirstNodeById($fileId);
                if (null === $node) {
                    return;
                }
                $share = $this->shares->newShare();
                $share->setNode($node)->setShareType(\OCP\Share\IShare::TYPE_USER)->setSharedWith($uid)
                    ->setSharedBy($job->getUid())->setPermissions(\OCP\Constants::PERMISSION_READ)
                ;
                $this->shares->createShare($share);
            } catch (\Throwable $e) {
                $this->logger->info('Clip: could not share with '.$uid.': '.$e->getMessage());
            }

            try {
                $notification = $this->notifications->createNotification();
                $notification->setApp(Application::APPNAME)
                    ->setUser($uid)
                    ->setObject('video-mention', (string) $job->getId())
                    ->setDateTime(new \DateTime())
                    ->setSubject(Notifier::SUBJECT_VIDEO_MENTION, ['name' => $job->getResultName(), 'by' => $job->getUid(), 'fileid' => $fileId])
                ;
                $this->notifications->notify($notification);
            } catch (\Throwable $e) {
                $this->logger->info('Clip: could not notify '.$uid.': '.$e->getMessage());
            }
        }
    }

    /** Put the job on the job list and, when possible, start a worker now. */
    private function schedule(VideoJob $job): void
    {
        $this->jobList->add(VideoJobRunner::class, ['id' => (int) $job->getId()]);
        $this->kick((int) $job->getId());
    }

    /**
     * Start "occ memories:video-run <id>" detached, so the video is made now and not at the
     * next cron run. Fails silently on hosts where exec is off: cron picks the job up then.
     */
    private function kick(int $id): void
    {
        if (!\function_exists('exec') || !\function_exists('shell_exec')) {
            return;
        }
        $php = '';
        foreach ([PHP_BINARY, '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if ('' !== $candidate && is_executable($candidate) && !str_contains(basename($candidate), 'fpm')) {
                $php = $candidate;

                break;
            }
        }
        if ('' === $php) {
            return;
        }
        $occ = \OC::$SERVERROOT.'/occ';
        $nice = max(0, min(19, (int) SystemConfig::get('memories.clips.nice')));
        $cmd = \sprintf('nohup nice -n %d %s %s memories:video-run %d > /dev/null 2>&1 &', $nice, escapeshellarg($php), escapeshellarg($occ), $id);

        try {
            exec($cmd);
        } catch (\Throwable $e) {
            $this->logger->debug('Video job: could not start a worker now: '.$e->getMessage());
        }
    }

    private function notify(VideoJob $job): void
    {
        if (!\in_array($job->getStatus(), [VideoJob::DONE, VideoJob::FAILED], true)) {
            return;
        }
        if (VideoJob::DONE === $job->getStatus()) {
            $this->shareWithMentioned($job);
        }

        try {
            $notification = $this->notifications->createNotification();
            $notification->setApp(Application::APPNAME)
                ->setUser($job->getUid())
                ->setObject('video-job', (string) $job->getId())
                ->setDateTime(new \DateTime())
                ->setSubject(VideoJob::DONE === $job->getStatus() ? Notifier::SUBJECT_VIDEO_READY : Notifier::SUBJECT_VIDEO_FAILED, [
                    'name' => $job->getResultName(),
                    'folder' => $job->getResultFolder(),
                    'error' => (string) $job->getError(),
                    'fileid' => (int) $job->getResultFileid(),
                ])
            ;
            $this->notifications->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Video job: notification failed', ['exception' => $e]);
        }
    }
}
