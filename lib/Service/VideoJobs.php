<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Cron\VideoJobRunner;
use OCA\Memories\Db\VideoJob;
use OCA\Memories\Db\VideoJobMapper;
use OCA\Memories\Notification\Notifier;
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
        private LoggerInterface $logger,
    ) {}

    /** @param list<int> $fileIds */
    public function create(string $uid, array $fileIds, float $fps, string $music, string $title = ''): VideoJob
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
        $job = $this->mapper->insert($job);
        $this->schedule($job);

        return $job;
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

    /** @return list<array> */
    public function listForUser(string $uid): array
    {
        return array_map(static fn (VideoJob $j) => $j->toArray(), $this->mapper->findForUser($uid));
    }

    /** @return list<array> */
    public function listAll(): array
    {
        return array_map(static fn (VideoJob $j) => $j->toArray(), $this->mapper->findAll());
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
        $cmd = \sprintf('nohup %s %s memories:video-run %d > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($occ), $id);

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
