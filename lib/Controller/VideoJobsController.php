<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Exceptions;
use OCA\Memories\Service\VideoJobs;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * What a person sees of their videos in the making (and can cancel, retry, remove); the
 * administrator sees everyone's.
 */
final class VideoJobsController extends GenericApiController
{
    #[NoAdminRequired]
    public function list(): Http\Response
    {
        return Util::guardEx(fn () => new JSONResponse($this->jobs()->listForUser(Util::getUID()), Http::STATUS_OK));
    }

    #[NoAdminRequired]
    public function cancel(int $id): Http\Response
    {
        return $this->act($id, 'cancel', false);
    }

    #[NoAdminRequired]
    public function retry(int $id): Http\Response
    {
        return $this->act($id, 'retry', false);
    }

    #[NoAdminRequired]
    public function delete(int $id): Http\Response
    {
        return $this->act($id, 'delete', false);
    }

    /**
     * Which photos go into the clip: from a selection, an album or an event; all, the best or random.
     *
     * @param list<int> $fileids
     */
    #[NoAdminRequired]
    public function pick(array $fileids = [], string $albumUser = '', string $albumName = '', int $event = 0, string $mode = 'best', int $n = 24): Http\Response
    {
        return Util::guardEx(static function () use ($fileids, $albumUser, $albumName, $event, $mode, $n) {
            $picker = \OC::$server->get(\OCA\Memories\Service\ClipPicker::class);

            /** @var list<int> $candidates */
            $candidates = [];
            foreach ($fileids as $id) {
                $candidates[] = (int) $id;
            }
            if ('' !== $albumName) {
                $candidates = $picker->albumPhotos('' !== $albumUser ? $albumUser : Util::getUID(), $albumName);
            } elseif ($event > 0) {
                $candidates = $picker->eventPhotos($event);
            }
            if (\count($candidates) < 2) {
                throw Exceptions::BadRequest('not enough photos');
            }

            return new JSONResponse(['fileids' => $picker->pick($candidates, $mode, $n), 'total' => \count($candidates)], Http::STATUS_OK);
        });
    }

    /** A track for the mood, to hear in the preview (the same one is used for the clip). */
    #[NoAdminRequired]
    public function musicPick(string $mood = 'calm', int $seconds = 30, array $fileids = []): Http\Response
    {
        return Util::guardEx(static function () use ($mood, $seconds, $fileids) {
            $music = \OC::$server->get(\OCA\Memories\Service\Music\MusicService::class);
            if (!$music->enabled()) {
                return new JSONResponse(['track' => null, 'mood' => $mood], Http::STATUS_OK);
            }
            $chosen = $music->mood($mood, array_values(array_map('intval', $fileids)));
            $track = $music->pick($chosen['mood'], max(10, min(600, $seconds)));

            return new JSONResponse(['track' => $track?->toArray(), 'mood' => $chosen['mood'], 'detected' => $chosen['detected']], Http::STATUS_OK);
        });
    }

    /** Remove a clip together with its video file. */
    #[NoAdminRequired]
    public function removeWithFile(int $id): Http\Response
    {
        return Util::guardEx(function () use ($id) {
            $jobs = $this->jobs();
            $job = $jobs->get($id);
            if (null === $job || $job->getUid() !== Util::getUID()) {
                throw Exceptions::NotFound('clip');
            }
            $jobs->remove($job, true);

            return new JSONResponse(['ok' => true], Http::STATUS_OK);
        });
    }

    // ---- administrator: every user's videos ----

    public function listAll(): Http\Response
    {
        return Util::guardEx(fn () => new JSONResponse($this->jobs()->listAll(), Http::STATUS_OK));
    }

    public function cancelAny(int $id): Http\Response
    {
        return $this->act($id, 'cancel', true);
    }

    public function retryAny(int $id): Http\Response
    {
        return $this->act($id, 'retry', true);
    }

    public function deleteAny(int $id): Http\Response
    {
        return $this->act($id, 'delete', true);
    }

    private function act(int $id, string $what, bool $admin): Http\Response
    {
        return Util::guardEx(function () use ($id, $what, $admin) {
            $jobs = $this->jobs();
            $job = $jobs->get($id);
            if (null === $job || (!$admin && $job->getUid() !== Util::getUID())) {
                throw Exceptions::NotFound('video job');
            }

            switch ($what) {
                case 'cancel':
                    $job = $jobs->cancel($job);

                    break;

                case 'retry':
                    $job = $jobs->retry($job);

                    break;

                case 'delete':
                    $jobs->delete($job);

                    return new JSONResponse(['ok' => true], Http::STATUS_OK);
            }

            return new JSONResponse($job->toArray(), Http::STATUS_OK);
        });
    }

    private function jobs(): VideoJobs
    {
        return \OC::$server->get(VideoJobs::class);
    }
}
