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
        return Util::guardEx(static function () {
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
            $albumId = $album->getId();

            // what the album already holds, then the rest in a few bulk inserts (an event can have
            // thousands of photos; one query per photo took a quarter of a minute)
            $query = $this->connection->getQueryBuilder();
            $query->select('file_id')->from('photos_albums_files')
                ->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
            ;
            $have = array_fill_keys(array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN)), true);
            $missing = array_values(array_filter($fileIds, static fn (int $id) => !isset($have[$id])));
            $added = 0;
            $now = time();
            foreach (array_chunk($missing, 500) as $chunk) {
                $this->connection->beginTransaction();

                try {
                    foreach ($chunk as $fileId) {
                        $ins = $this->connection->getQueryBuilder();
                        $ins->insert('photos_albums_files')->values([
                            'album_id' => $ins->createNamedParameter($albumId, IQueryBuilder::PARAM_INT),
                            'file_id' => $ins->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                            'added' => $ins->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                            'owner' => $ins->createNamedParameter($uid),
                        ]);
                        $added += $ins->executeStatement();
                    }
                    $this->connection->commit();
                } catch (\Throwable $e) {
                    $this->connection->rollBack();

                    throw $e;
                }
            }
            if (\count($missing) > 0) {
                $upd = $this->connection->getQueryBuilder();
                $upd->update('photos_albums')
                    ->set('last_added_photo', $upd->createNamedParameter($missing[\count($missing) - 1], IQueryBuilder::PARAM_INT))
                    ->where($upd->expr()->eq('album_id', $upd->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
                ;
                $upd->executeStatement();
            }

            return new JSONResponse(['album_id' => $albumId, 'name' => $album->getTitle(), 'added' => $added], Http::STATUS_OK);
        });
    }
}
