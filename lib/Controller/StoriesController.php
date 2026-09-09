<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Service\Stories;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Stories: photos shown one after the other, full screen. The list holds both the ones the
 * person made and the ones the server proposed.
 */
final class StoriesController extends GenericApiController
{
    #[NoAdminRequired]
    public function list(): Http\Response
    {
        return Util::guardEx(fn () => new JSONResponse($this->stories()->list(Util::getUID()), Http::STATUS_OK));
    }

    #[NoAdminRequired]
    public function photos(int $id): Http\Response
    {
        return Util::guardEx(fn () => new JSONResponse($this->stories()->photos(Util::getUID(), $id), Http::STATUS_OK));
    }

    /**
     * Make a story out of the photos the person picked.
     *
     * @param list<int> $fileids
     */
    #[NoAdminRequired]
    public function create(array $fileids = [], string $title = '', string $mode = 'all'): Http\Response
    {
        return Util::guardEx(function () use ($fileids, $title, $mode) {
            $ids = [];
            foreach ($fileids as $id) {
                $ids[] = (int) $id;
            }

            $uid = Util::getUID();
            $id = $this->stories()->create($uid, $title, $ids, '', '', $mode);

            return new JSONResponse(['id' => $id], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    public function rename(int $id, string $title): Http\Response
    {
        return Util::guardEx(function () use ($id, $title) {
            $this->stories()->rename(Util::getUID(), $id, $title);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    public function delete(int $id): Http\Response
    {
        return Util::guardEx(function () use ($id) {
            $this->stories()->delete(Util::getUID(), $id);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    /** Where the person stopped watching (0 = to the end). */
    #[NoAdminRequired]
    public function seen(int $id, int $fileid = 0): Http\Response
    {
        return Util::guardEx(function () use ($id, $fileid) {
            $this->stories()->markSeen(Util::getUID(), $id, $fileid);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    private function stories(): Stories
    {
        return \OC::$server->get(Stories::class);
    }
}
