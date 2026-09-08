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
