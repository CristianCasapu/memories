<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Db\VideoJob;
use OCA\Memories\Db\VideoJobMapper;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\Music\MusicService;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Makes one video from photos (Google Photos "animation" style): frames from the previews, so
 * HEIC / RAW work; ffmpeg; background music chosen by the mood of the photos; the file saved
 * next to the first photo. Progress and the outcome go into the job row, so the person who
 * asked for it (and the administrator) can see what is happening.
 */
final class VideoMaker
{
    public const MAX_FRAMES = 200;
    public const FRAME_SIZE = 1920;

    public function __construct(
        private VideoJobMapper $jobs,
        private MusicService $music,
        private IRootFolder $rootFolder,
        private IPreview $preview,
        private ITempManager $tempManager,
        private IDBConnection $connection,
        private LoggerInterface $logger,
    ) {}

    /** Run a queued job to the end; the row carries the outcome. */
    public function run(VideoJob $job): VideoJob
    {
        if (VideoJob::QUEUED !== $job->getStatus()) {
            return $job;
        }
        $job->setStatus(VideoJob::RUNNING);
        $job->setStarted(time());
        $this->progress($job, 0, 'Preparing the photos');

        try {
            $this->make($job);
            $job->setStatus(VideoJob::DONE);
            $this->progress($job, 100, 'Done');
        } catch (\Throwable $e) {
            $this->logger->warning('Video job '.$job->getId().' failed: '.$e->getMessage(), ['exception' => $e]);
            $job->setStatus(VideoJob::FAILED);
            $job->setError(mb_substr($e->getMessage(), 0, 2000));
        }
        $job->setFinished(time());
        $this->tempManager->clean();

        return $this->jobs->update($job);
    }

    private function make(VideoJob $job): void
    {
        $ffmpeg = (string) SystemConfig::get('memories.vod.ffmpeg');
        if ('' === $ffmpeg || !is_executable($ffmpeg)) {
            throw Exceptions::NotEnabled('ffmpeg (Administration › Memories › Video: ffmpeg path)');
        }
        $fileids = array_values(array_unique($job->fileIdList()));
        if (\count($fileids) < 2) {
            throw Exceptions::MissingParameter('at least 2 photos');
        }
        if (\count($fileids) > self::MAX_FRAMES) {
            throw Exceptions::BadRequest('too many photos (max '.self::MAX_FRAMES.')');
        }
        $fps = max(0.25, min(15.0, $job->getFps()));

        // order by capture time
        $query = $this->connection->getQueryBuilder();
        $query->select('fileid')->from('memories')
            ->where($query->expr()->in('fileid', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('datetaken', 'ASC')->addOrderBy('fileid', 'ASC')
        ;
        $ordered = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

        /** @var list<int> $ordered */
        $ordered = array_merge($ordered, array_diff($fileids, $ordered));

        $userFolder = $this->rootFolder->getUserFolder($job->getUid());
        $dir = (string) $this->tempManager->getTemporaryFolder();
        if ('' === $dir) {
            throw new \Exception('no temporary folder');
        }

        $first = null;
        $n = 0;
        $total = \count($ordered);
        foreach ($ordered as $i => $fileId) {
            if ($this->cancelled($job)) {
                throw new \Exception('cancelled');
            }
            $node = $userFolder->getFirstNodeById($fileId);
            if (!$node instanceof File || !str_starts_with($node->getMimeType(), 'image/')) {
                continue;
            }
            $first ??= $node;

            try {
                $img = $this->preview->getPreview($node, self::FRAME_SIZE, self::FRAME_SIZE, false, IPreview::MODE_FILL);
                file_put_contents(\sprintf('%s/frame_%04d.jpg', $dir, $n++), $img->getContent());
            } catch (\Throwable $e) {
                $this->logger->warning('Video job: preview failed for '.$fileId, ['exception' => $e]);
            }
            if (0 === $i % 5) {
                $this->progress($job, (int) (5.0 + 55.0 * (float) ($i + 1) / (float) $total), \sprintf('Preparing the photos (%d of %d)', $i + 1, $total));
            }
        }
        if ($n < 2 || null === $first) {
            throw Exceptions::BadRequest('not enough usable photos');
        }

        $this->progress($job, 62, 'Encoding the video');
        $out = $dir.'/burst.mp4';
        $cmd = [
            $ffmpeg, '-y', '-loglevel', 'error', '-framerate', (string) $fps, '-i', $dir.'/frame_%04d.jpg',
            '-vf', \sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,format=yuv420p', self::FRAME_SIZE, 1080, self::FRAME_SIZE, 1080),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-r', '30', '-movflags', '+faststart', $out,
        ];
        [, $stderr] = Util::execSafe2($cmd, 300000, null, false, true);
        if (!is_file($out) || filesize($out) < 100) {
            throw new \Exception('ffmpeg failed: '.trim((string) $stderr));
        }

        // background music, chosen by the mood of the photos (or the one asked for)
        if ('none' !== $job->getMusic() && $this->music->enabled()) {
            try {
                $this->progress($job, 78, 'Reading the mood of the photos');
                $seconds = (float) $n / $fps;
                $chosen = $this->music->mood($job->getMusic(), $ordered);
                $job->setMood($chosen['mood']);
                $this->progress($job, 84, 'Looking for music: '.Music\Mood::label($chosen['mood']));
                $track = $this->music->pick($chosen['mood'], (int) ceil($seconds));
                if (null !== $track) {
                    $this->progress($job, 90, 'Adding the music: '.$track->credit());
                    $out = $this->music->mux($ffmpeg, $out, $track, $seconds, $chosen['mood']);
                    $job->setTrack(json_encode($track->toArray()) ?: null);
                } else {
                    $this->logger->info('Video job '.$job->getId().': no track found for '.$chosen['mood']);
                }
            } catch (\Throwable $e) {
                // the video is worth more than the music: save it silent, but say so
                $this->logger->warning('Video job '.$job->getId().': music failed, saving without', ['exception' => $e]);
                $job->setStep('Music failed ('.mb_substr($e->getMessage(), 0, 120).'), saved without');
            }
        }
        if ($this->cancelled($job)) {
            throw new \Exception('cancelled');
        }

        $this->progress($job, 96, 'Saving the video');
        $parent = $first->getParent();
        $base = '' !== trim($job->getTitle()) ? trim($job->getTitle()) : pathinfo($first->getName(), PATHINFO_FILENAME).'-video';
        $base = preg_replace('/[\/\\\:*?"<>|]+/', '-', $base) ?? $base;
        $target = $base.'.mp4';
        for ($i = 2; $parent->nodeExists($target); ++$i) {
            $target = "{$base} ({$i}).mp4";
        }
        $file = $parent->newFile($target, fopen($out, 'r'));
        $job->setResultFileid($file->getId());
        $job->setResultName($file->getName());
        $job->setResultFolder((string) $userFolder->getRelativePath($parent->getPath()));
        $job->setPhotos($n);
    }

    private function progress(VideoJob $job, int $percent, string $step): void
    {
        $job->setProgress(max(0, min(100, $percent)));
        $job->setStep($step);
        $this->jobs->update($job);
    }

    /** Was the job cancelled from the outside while running? */
    private function cancelled(VideoJob $job): bool
    {
        try {
            return VideoJob::CANCELLED === $this->jobs->find((int) $job->getId())->getStatus();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
