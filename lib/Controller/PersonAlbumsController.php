<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Service\PersonAlbums;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Albums that follow a recognized person (see Service\PersonAlbums).
 */
final class PersonAlbumsController extends GenericApiController
{
    #[NoAdminRequired]
    public function get(int $clusterId): Http\Response
    {
        return Util::guardEx(function () use ($clusterId) {
            $uid = Util::getUID();
            $service = \OC::$server->get(PersonAlbums::class);

            return new JSONResponse([
                'available' => PersonAlbums::isAvailable(),
                'album' => $service->getForCluster($uid, $clusterId),
            ], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    public function create(int $clusterId): Http\Response
    {
        return Util::guardEx(function () use ($clusterId) {
            $uid = Util::getUID();
            $service = \OC::$server->get(PersonAlbums::class);

            return new JSONResponse($service->create($uid, $clusterId), Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    public function unlink(int $clusterId): Http\Response
    {
        return Util::guardEx(function () use ($clusterId) {
            $uid = Util::getUID();
            \OC::$server->get(PersonAlbums::class)->unlink($uid, $clusterId);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }
}
