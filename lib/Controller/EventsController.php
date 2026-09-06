<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Exceptions;
use OCA\Memories\Service\Events;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Automatic events: rebuild on demand and "save as album".
 */
final class EventsController extends GenericApiController
{
    #[NoAdminRequired]
    public function rebuild(): Http\Response
    {
        return Util::guardEx(function () {
            $uid = Util::getUID();
            $n = \OC::$server->get(Events::class)->rebuild($uid);

            return new JSONResponse(['events' => $n], Http::STATUS_OK);
        });
    }

    /**
     * Create a Photos album with the photos of the event (named after the event).
     */
    #[NoAdminRequired]
    public function createAlbum(int $eventId): Http\Response
    {
        return Util::guardEx(function () use ($eventId) {
            $uid = Util::getUID();
            if (!class_exists(\OCA\Photos\Album\AlbumMapper::class)) {
                throw Exceptions::NotEnabled('Photos (albums)');
            }
            $query = $this->connection->getQueryBuilder();
            $query->select('id', 'title', 'start', 'end')->from('memories_events')
                ->where($query->expr()->eq('id', $query->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
                ->andWhere($query->expr()->eq('uid', $query->createNamedParameter($uid)))
            ;
            $event = $query->executeQuery()->fetch();
            if (!$event) {
                throw Exceptions::NotFound('event');
            }
            $query = $this->connection->getQueryBuilder();
            $query->select('fileid')->from('memories_events_files')
                ->where($query->expr()->eq('event_id', $query->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)))
            ;
            $fileIds = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

            $name = trim(('' !== (string) $event['title'] ? $event['title'].' ' : '').date('Y-m-d', (int) $event['start']));
            /** @var \OCA\Photos\Album\AlbumMapper $mapper */
            $mapper = \OC::$server->get(\OCA\Photos\Album\AlbumMapper::class);
            $album = $mapper->getByName($name, $uid) ?? $mapper->create($uid, $name);
            $added = 0;
            foreach ($fileIds as $fileId) {
                if (null === $mapper->getForAlbumIdAndFileId($album->getId(), $fileId)) {
                    try {
                        $mapper->addFile($album->getId(), $fileId, $uid);
                        ++$added;
                    } catch (\Throwable $e) {
                    }
                }
            }

            return new JSONResponse(['album_id' => $album->getId(), 'name' => $album->getTitle(), 'added' => $added], Http::STATUS_OK);
        });
    }
}
