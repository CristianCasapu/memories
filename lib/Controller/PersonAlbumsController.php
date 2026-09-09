<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Db\AlbumsQuery;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\PersonAlbums;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Albums that follow a recognized person (see Service\PersonAlbums).
 */
final class PersonAlbumsController extends GenericApiController
{
    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserConfig $userConfig,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager,
        LoggerInterface $logger,
        TimelineQuery $tq,
        FsManager $fs,
        private AlbumsQuery $albumsQuery,
    ) {
        parent::__construct($request, $config, $userConfig, $userSession, $connection, $rootFolder, $appManager, $logger, $tq, $fs);
    }

    #[NoAdminRequired]
    public function get(int $clusterId): Http\Response
    {
        return Util::guardEx(static function () use ($clusterId) {
            $uid = Util::getUID();
            $service = \OC::$server->get(PersonAlbums::class);

            return new JSONResponse([
                'available' => PersonAlbums::isAvailable(),
                'album' => $service->getForCluster($uid, $clusterId),
            ], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    public function create(int $clusterId, bool $prominence = false, bool $subjects = false): Http\Response
    {
        return Util::guardEx(static function () use ($clusterId, $prominence, $subjects) {
            $uid = Util::getUID();
            $service = \OC::$server->get(PersonAlbums::class);

            return new JSONResponse($service->create($uid, $clusterId, $prominence, $subjects), Http::STATUS_OK);
        });
    }

    /**
     * Whether this album follows a person, and how its photos should be ordered.
     */
    #[NoAdminRequired]
    public function forAlbum(string $user, string $name): Http\Response
    {
        return Util::guardEx(function () use ($user, $name) {
            $album = $this->albumsQuery->getIfAllowed(Util::getUID(), "{$user}/{$name}");
            if (null === $album) {
                throw Exceptions::NotFound("album {$user}/{$name}");
            }

            $link = \OC::$server->get(PersonAlbums::class)->getForAlbum((int) $album['album_id']);
            if (null === $link) {
                return new JSONResponse(['person' => false, 'prominence' => false, 'subjects' => false], Http::STATUS_OK);
            }

            return new JSONResponse([
                'person' => true,
                'prominence' => $link['prominence'],
                'subjects' => $link['subjects'],
            ], Http::STATUS_OK);
        });
    }

    /**
     * Remember the photo order of the album of a person (owner only).
     */
    #[NoAdminRequired]
    public function setOptions(string $user, string $name, bool $prominence = false, bool $subjects = false): Http\Response
    {
        return Util::guardEx(function () use ($user, $name, $prominence, $subjects) {
            $uid = Util::getUID();
            $album = $this->albumsQuery->getIfAllowed($uid, "{$user}/{$name}");
            if (null === $album) {
                throw Exceptions::NotFound("album {$user}/{$name}");
            }

            $service = \OC::$server->get(PersonAlbums::class);
            $link = $service->getForAlbum((int) $album['album_id']);
            if (null === $link || $link['uid'] !== $uid) {
                throw Exceptions::Forbidden('not the owner of this album of a person');
            }

            $service->setOptions($link['album_id'], $prominence, $subjects);

            return new JSONResponse(['prominence' => $prominence, 'subjects' => $subjects], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    public function unlink(int $clusterId): Http\Response
    {
        return Util::guardEx(static function () use ($clusterId) {
            $uid = Util::getUID();
            \OC::$server->get(PersonAlbums::class)->unlink($uid, $clusterId);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }
}
