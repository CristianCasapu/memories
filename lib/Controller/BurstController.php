<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Exceptions;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Turn a burst (or any selection of photos) into a short video, Google Photos "animation" style.
 * Frames come from Nextcloud previews, so HEIC / RAW files work too. The video is saved next to
 * the first photo, so it shows up in the timeline right away.
 */
final class BurstController extends GenericApiController
{
    public const MAX_FRAMES = 200;
    public const FRAME_SIZE = 1920;

    /**
     * Queue a video from these photos; the answer is the job (see VideoJobsController for progress).
     *
     * @param list<int> $fileids photos (any order; sorted by capture time)
     * @param float     $fps     frames per second (seconds per photo = 1/fps)
     * @param string    $music   'auto' (mood from the photos), 'none', or a mood id
     */
    #[NoAdminRequired]
    public function video(array $fileids = [], float $fps = 3.0, string $name = '', string $music = 'auto'): Http\Response
    {
        return Util::guardEx(static function () use ($fileids, $fps, $name, $music) {
            $fileids = array_values(array_unique(array_map('intval', $fileids)));
            if (\count($fileids) < 2) {
                throw Exceptions::MissingParameter('at least 2 photos');
            }
            if (\count($fileids) > \OCA\Memories\Service\VideoMaker::MAX_FRAMES) {
                throw Exceptions::BadRequest('too many photos (max '.\OCA\Memories\Service\VideoMaker::MAX_FRAMES.')');
            }
            $ffmpeg = (string) SystemConfig::get('memories.vod.ffmpeg');
            if ('' === $ffmpeg || !is_executable($ffmpeg)) {
                throw Exceptions::NotEnabled('ffmpeg (set memories.vod.ffmpeg)');
            }
            $job = \OC::$server->get(\OCA\Memories\Service\VideoJobs::class)->create(Util::getUID(), $fileids, max(0.25, min(15.0, $fps)), $music, $name);

            return new JSONResponse(['queued' => true, 'job' => $job->toArray()], Http::STATUS_OK);
        });
    }
}
