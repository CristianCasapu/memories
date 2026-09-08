<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Settings\SystemConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ITagManager;
use Psr\Log\LoggerInterface;

/**
 * Google Takeout sidecars: "photo.jpg.supplemental-metadata.json" (or the older "photo.jpg.json",
 * or a truncated "photo.jpg.supplemental-metad.json") next to the photo. What EXIF does not have
 * is taken from there when the photo is indexed: the time it was taken, the location, the
 * description, the people, and whether it was a favourite. Nothing is written into the file.
 */
final class Takeout
{
    /** Marker stored with the EXIF json: which fields came from the sidecar */
    public const MARKER = 'GoogleTakeout';

    /** Folder listings, one folder at a time (indexing walks folder by folder) */
    private static ?int $cacheFolder = null;

    /** @var array<string, File> */
    private static array $cacheJson = [];

    public function __construct(
        private ITagManager $tagManager,
        private LoggerInterface $logger,
    ) {}

    public static function enabled(): bool
    {
        return (bool) SystemConfig::get('memories.takeout.import');
    }

    /**
     * The sidecar of a photo, when there is one in the same folder.
     */
    public static function findSidecar(File $file): ?File
    {
        $parent = $file->getParent();
        $jsons = self::jsonFiles($parent);
        if (0 === \count($jsons)) {
            return null;
        }

        $name = $file->getName();
        $candidates = [$name];
        // "photo-edited.jpg" shares the sidecar of "photo.jpg"
        if (preg_match('/^(.*)-(edited|bearbeitet|editat[aă]?|modifi[eé])(\.[^.]+)$/iu', $name, $m)) {
            $candidates[] = $m[1].$m[3];
        }
        // "photo(1).jpg" has "photo.jpg(1).json"
        if (preg_match('/^(.*)(\(\d+\))(\.[^.]+)$/u', $name, $m)) {
            $candidates[] = $m[1].$m[3].$m[2];
        }

        foreach ($candidates as $candidate) {
            $re = '/^'.preg_quote($candidate, '/').'(\.[A-Za-z\-]*)?\.json$/u';
            foreach ($jsons as $jsonName => $json) {
                if (preg_match($re, $jsonName)) {
                    return $json;
                }
            }
        }

        // Google keeps the whole sidecar name at 51 characters: a long photo name is cut
        foreach ($jsons as $jsonName => $json) {
            $stem = preg_replace('/(\.[A-Za-z\-]*)?\.json$/u', '', $jsonName) ?? $jsonName;
            if (mb_strlen($stem) >= 30 && mb_strlen($stem) < mb_strlen($name) && str_starts_with($name, $stem)) {
                return $json;
            }
        }

        return null;
    }

    /**
     * What the sidecar says, normalised.
     *
     * @return null|array{epoch: ?int, lat: ?float, lon: ?float, alt: ?float, description: string, people: list<string>, favorited: bool}
     */
    public static function parse(File $json): ?array
    {
        if ($json->getSize() > 65536) {
            return null;
        }
        $data = json_decode((string) $json->getContent(), true);
        if (!\is_array($data) || (!isset($data['title']) && !isset($data['url']) && !isset($data['photoTakenTime']))) {
            return null;
        }

        $epoch = null;
        $ts = $data['photoTakenTime']['timestamp'] ?? null;
        if (is_numeric($ts) && (int) $ts > 0) {
            $epoch = (int) $ts;
        }

        $lat = $lon = $alt = null;
        foreach (['geoDataExif', 'geoData'] as $key) {
            $geo = $data[$key] ?? null;
            if (!\is_array($geo)) {
                continue;
            }
            $la = (float) ($geo['latitude'] ?? 0);
            $lo = (float) ($geo['longitude'] ?? 0);
            if ((abs($la) > 0.00001 || abs($lo) > 0.00001) && abs($la) <= 90 && abs($lo) <= 180) {
                $lat = $la;
                $lon = $lo;
                $al = (float) ($geo['altitude'] ?? 0);
                $alt = 0.0 !== $al ? $al : null;

                break;
            }
        }

        $people = [];
        foreach (\is_array($data['people'] ?? null) ? $data['people'] : [] as $p) {
            $n = trim((string) (\is_array($p) ? ($p['name'] ?? '') : $p));
            if ('' !== $n && !\in_array($n, $people, true)) {
                $people[] = mb_substr($n, 0, 100);
            }
        }

        return [
            'epoch' => $epoch,
            'lat' => $lat,
            'lon' => $lon,
            'alt' => $alt,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 2000),
            'people' => \array_slice($people, 0, 50),
            'favorited' => (bool) ($data['favorited'] ?? false),
        ];
    }

    /**
     * Before the location is processed: coordinates and description, only where EXIF has none.
     *
     * @param array<string, mixed> $exif
     *
     * @return list<string> the fields taken from the sidecar
     */
    public static function applyBeforeLocation(array &$exif, array $meta): array
    {
        $applied = [];
        $hasGps = self::validCoord($exif['GPSLatitude'] ?? null, $exif['GPSLongitude'] ?? null);
        if (!$hasGps && null !== $meta['lat'] && null !== $meta['lon']) {
            $exif['GPSLatitude'] = $meta['lat'];
            $exif['GPSLongitude'] = $meta['lon'];
            if (null !== $meta['alt']) {
                $exif['GPSAltitude'] = $meta['alt'];
            }
            $applied[] = 'location';
        }
        if ('' !== $meta['description'] && '' === trim((string) ($exif['Description'] ?? $exif['ImageDescription'] ?? ''))) {
            $exif['Description'] = $meta['description'];
            $applied[] = 'description';
        }
        if (\count($meta['people']) > 0 && empty($exif['PersonInImage'])) {
            $exif['PersonInImage'] = implode(', ', $meta['people']);
            $applied[] = 'people';
        }

        return $applied;
    }

    /**
     * After the location is processed (the time zone of the place is known): the time taken,
     * only when EXIF has no usable date. Written as local time in that zone with its offset,
     * so the day and hour shown are the ones of the place, as with a real EXIF date.
     *
     * @param array<string, mixed> $exif
     *
     * @return list<string>
     */
    public static function applyAfterLocation(array &$exif, array $meta): array
    {
        if (null === $meta['epoch']) {
            return [];
        }

        try {
            \OCA\Memories\Exif::parseExifDate($exif);

            return []; // EXIF has a date: keep it
        } catch (\Throwable) {
        }

        $tz = null;
        foreach ([$exif['LocationTZID'] ?? null, SystemConfig::get('default_timezone') ?: null, getenv('TZ') ?: null, date_default_timezone_get()] as $name) {
            if (\is_string($name) && '' !== $name) {
                try {
                    $tz = new \DateTimeZone($name);

                    break;
                } catch (\Throwable) {
                }
            }
        }
        $date = (new \DateTime('@'.$meta['epoch']))->setTimezone($tz ?? new \DateTimeZone('UTC'));
        $exif['DateTimeOriginal'] = $date->format('Y:m:d H:i:s');
        $exif['OffsetTimeOriginal'] = $date->format('P');

        return ['date'];
    }

    /** Mark the photo as a favourite of its owner (never un-favourites) */
    public function favourite(File $file): bool
    {
        try {
            $uid = $file->getOwner()?->getUID();
            if (null === $uid || '' === $uid) {
                return false;
            }
            $tags = $this->tagManager->load('files', [], false, $uid);
            if (null === $tags) {
                return false;
            }
            $tags->addToFavorites($file->getId());

            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('Takeout: could not favourite '.$file->getPath().': '.$e->getMessage());

            return false;
        }
    }

    /** Photos of a folder (recursively) that have a sidecar */
    public static function walk(Folder $folder, callable $onPhoto): void
    {
        if ($folder->nodeExists('.nomedia') || $folder->nodeExists('.nomemories')) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                self::walk($node, $onPhoto);
            } elseif ($node instanceof File && Index::isSupported($node) && null !== self::findSidecar($node)) {
                $onPhoto($node);
            }
        }
    }

    /** Forget the folder cache (after a folder changed) */
    public static function reset(): void
    {
        self::$cacheFolder = null;
        self::$cacheJson = [];
    }

    /** @return array<string, File> json files of the folder, by name */
    private static function jsonFiles(Folder $folder): array
    {
        $id = $folder->getId();
        if (self::$cacheFolder === $id) {
            return self::$cacheJson;
        }
        $jsons = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && str_ends_with(strtolower($node->getName()), '.json')) {
                $jsons[$node->getName()] = $node;
            }
        }
        self::$cacheFolder = $id;
        self::$cacheJson = $jsons;

        return $jsons;
    }

    private static function validCoord(mixed $lat, mixed $lon): bool
    {
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return false;
        }
        $la = (float) $lat;
        $lo = (float) $lon;

        return abs($la) <= 90 && abs($lo) <= 180 && (abs($la) > 0.00001 || abs($lo) > 0.00001);
    }
}
