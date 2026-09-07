<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Exceptions;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IPreview;
use OCP\ITempManager;

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
     * @param list<int> $fileids photos (any order; sorted by capture time)
     * @param float     $fps     frames per second (seconds per photo = 1/fps)
     */
    #[NoAdminRequired]
    public function video(array $fileids = [], float $fps = 3.0, string $name = ''): Http\Response
    {
        return Util::guardEx(function () use ($fileids, $fps, $name) {
            $ffmpeg = (string) SystemConfig::get('memories.vod.ffmpeg');
            if ('' === $ffmpeg || !is_executable($ffmpeg)) {
                throw Exceptions::NotEnabled('ffmpeg (set memories.vod.ffmpeg)');
            }
            $fileids = array_values(array_unique(array_map('intval', $fileids)));
            if (\count($fileids) < 2) {
                throw Exceptions::MissingParameter('at least 2 photos');
            }
            if (\count($fileids) > self::MAX_FRAMES) {
                throw Exceptions::BadRequest('too many photos (max '.self::MAX_FRAMES.')');
            }
            $fps = max(0.5, min(15.0, $fps));

            // order by capture time
            $query = $this->connection->getQueryBuilder();
            $query->select('fileid')->from('memories')
                ->where($query->expr()->in('fileid', $query->createNamedParameter($fileids, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('datetaken', 'ASC')->addOrderBy('fileid', 'ASC')
            ;
            $ordered = array_map('intval', $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
            $ordered = array_merge($ordered, array_diff($fileids, $ordered));

            $userFolder = Util::getUserFolder();
            $preview = \OC::$server->get(IPreview::class);
            $tmp = \OC::$server->get(ITempManager::class);
            $dir = $tmp->getTemporaryFolder();

            $first = null;
            $n = 0;
            foreach ($ordered as $fileId) {
                $node = $userFolder->getFirstNodeById($fileId);
                if (!$node instanceof File || !str_starts_with($node->getMimeType(), 'image/')) {
                    continue;
                }
                $first ??= $node;

                try {
                    $img = $preview->getPreview($node, self::FRAME_SIZE, self::FRAME_SIZE, false, IPreview::MODE_FILL);
                    file_put_contents(\sprintf('%s/frame_%04d.jpg', $dir, $n++), $img->getContent());
                } catch (\Throwable $e) {
                    $this->logger->warning('Burst video: preview failed for '.$fileId, ['exception' => $e]);
                }
            }
            if ($n < 2 || null === $first) {
                throw Exceptions::BadRequest('not enough usable photos');
            }

            $out = $dir.'/burst.mp4';
            $cmd = [
                $ffmpeg, '-y', '-loglevel', 'error', '-framerate', (string) $fps, '-i', $dir.'/frame_%04d.jpg',
                '-vf', \sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,format=yuv420p', self::FRAME_SIZE, 1080, self::FRAME_SIZE, 1080),
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-r', '30', '-movflags', '+faststart', $out,
            ];
            [, $stderr] = Util::execSafe2($cmd, 120000, null, false, true);
            if (!is_file($out) || filesize($out) < 100) {
                throw new \Exception('ffmpeg failed: '.trim((string) $stderr));
            }

            // save next to the first photo
            $parent = $first->getParent();
            $base = '' !== trim($name) ? trim($name) : pathinfo($first->getName(), PATHINFO_FILENAME).'-burst';
            $base = preg_replace('/[\\/\\\\:*?"<>|]+/', '-', $base) ?? $base;
            $target = $base.'.mp4';
            for ($i = 2; $parent->nodeExists($target); ++$i) {
                $target = "{$base} ({$i}).mp4";
            }
            $file = $parent->newFile($target, fopen($out, 'rb'));
            $tmp->clean();

            return new JSONResponse([
                'fileid' => $file->getId(),
                'name' => $file->getName(),
                'folder' => $userFolder->getRelativePath($parent->getPath()),
                'frames' => $n,
            ], Http::STATUS_OK);
        });
    }
}
