<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Settings\SystemConfig;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Housekeeping for the scratch files Nextcloud and its helpers leave behind: the Nextcloud
 * temporary directory, the system /tmp (only files with known Nextcloud/ImageMagick/PHP
 * names owned by the web user), the go-vod transcode cache, and optionally the trash bin
 * and file-version expiration jobs. Controlled and monitored from Administration › Memories.
 */
final class Cleanup
{
    public const CONFIG_DEFAULTS = [
        'enabled' => true,
        'tmpMaxAgeHours' => 24,
        'systemTmp' => true,
        'vodMaxAgeDays' => 7,
        'expireTrash' => false,
        'expireVersions' => false,
    ];
    public const HISTORY_SIZE = 30;
    /** file names Nextcloud, PHP, ImageMagick, exiftool and go-vod create in the system temp dir */
    public const SYSTEM_TMP_PATTERNS = [
        '/^magick-/', '/^oc_tmp_/', '/^oc-tmp-/', '/^oc_tmp/', '/^go-vod-/', '/^exiftool-/',
        '/^php[A-Za-z0-9]{6}$/', '/^sfi_file_sequence/', '/^memories-/', '/^recognize-/', '/^tf-/',
    ];
    public const NEVER_DELETE = ['nextcloud-cron.lock'];

    public function __construct(
        private IAppConfig $appConfig,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        $config = self::CONFIG_DEFAULTS;
        $stored = json_decode($this->appConfig->getValueString(Application::APPNAME, 'cleanup.config', '{}'), true);
        foreach (\is_array($stored) ? $stored : [] as $key => $value) {
            if (\array_key_exists($key, $config)) {
                $config[$key] = \is_bool($config[$key]) ? (bool) $value : max(0, (int) $value);
            }
        }

        return $config;
    }

    /** @param array<string, mixed> $values */
    public function setConfig(array $values): array
    {
        $config = $this->getConfig();
        foreach ($values as $key => $value) {
            if (\array_key_exists($key, self::CONFIG_DEFAULTS)) {
                $config[$key] = \is_bool(self::CONFIG_DEFAULTS[$key]) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : max(0, (int) $value);
            }
        }
        $this->appConfig->setValueString(Application::APPNAME, 'cleanup.config', json_encode($config));

        return $config;
    }

    /**
     * The places that get cleaned, with what would be removed right now.
     *
     * @return list<array<string, mixed>>
     */
    public function targets(bool $withPreview = true): array
    {
        $config = $this->getConfig();
        $ncTmp = rtrim((string) $this->tempManager->getTempBaseDir(), '/');
        $sysTmp = rtrim(sys_get_temp_dir(), '/');
        $vod = rtrim((string) (SystemConfig::get('memories.vod.tempdir') ?: $sysTmp.'/go-vod/'), '/');

        $targets = [
            ['id' => 'nc_tmp', 'label' => 'Nextcloud temporary directory', 'path' => $ncTmp, 'maxAge' => $config['tmpMaxAgeHours'] * 3600, 'patterns' => null, 'enabled' => true, 'rule' => 'files older than '.$config['tmpMaxAgeHours'].' h, empty folders, dangling links'],
        ];
        if ($sysTmp !== $ncTmp) {
            $targets[] = ['id' => 'system_tmp', 'label' => 'System temporary directory', 'path' => $sysTmp, 'maxAge' => $config['tmpMaxAgeHours'] * 3600, 'patterns' => self::SYSTEM_TMP_PATTERNS, 'enabled' => (bool) $config['systemTmp'], 'rule' => 'only Nextcloud / PHP / ImageMagick / exiftool / go-vod files owned by the web user, older than '.$config['tmpMaxAgeHours'].' h'];
        }
        if ($vod !== $ncTmp && $vod !== $sysTmp) {
            $targets[] = ['id' => 'vod', 'label' => 'Video transcode cache (go-vod)', 'path' => $vod, 'maxAge' => $config['vodMaxAgeDays'] * 86400, 'patterns' => null, 'enabled' => true, 'rule' => 'files older than '.$config['vodMaxAgeDays'].' days'];
        }
        $targets[] = ['id' => 'trash', 'label' => 'Trash bin expiration (Nextcloud retention rules)', 'path' => '', 'maxAge' => 0, 'patterns' => null, 'enabled' => (bool) $config['expireTrash'], 'rule' => 'runs the files_trashbin expiration job'];
        $targets[] = ['id' => 'versions', 'label' => 'File versions expiration (Nextcloud retention rules)', 'path' => '', 'maxAge' => 0, 'patterns' => null, 'enabled' => (bool) $config['expireVersions'], 'rule' => 'runs the files_versions expiration job'];

        foreach ($targets as &$target) {
            if ('' !== $target['path']) {
                $target['exists'] = is_dir($target['path']);
                $target['free'] = $target['exists'] ? (int) @disk_free_space($target['path']) : null;
                [$target['size'], $target['count']] = $target['exists'] ? $this->usage($target['path']) : [0, 0];
                if ($withPreview && $target['exists']) {
                    $r = $this->cleanDirectory($target, true);
                    $target['removable_files'] = $r['files'];
                    $target['removable_bytes'] = $r['bytes'];
                }
            }
            unset($target['patterns']);
        }

        return $targets;
    }

    /**
     * Run the cleanup.
     *
     * @return array<string, mixed> report
     */
    public function run(bool $dryRun = false, bool $force = false, ?callable $log = null): array
    {
        $config = $this->getConfig();
        $start = microtime(true);
        $report = ['time' => time(), 'dry_run' => $dryRun, 'targets' => [], 'files' => 0, 'bytes' => 0, 'errors' => [], 'duration' => 0.0, 'skipped' => false];
        if (!$config['enabled'] && !$force) {
            $report['skipped'] = true;

            return $report;
        }

        foreach ($this->targets(false) as $target) {
            if (!$target['enabled']) {
                continue;
            }
            $entry = ['id' => $target['id'], 'label' => $target['label'], 'path' => $target['path'], 'files' => 0, 'bytes' => 0, 'errors' => []];

            try {
                if ('trash' === $target['id'] || 'versions' === $target['id']) {
                    if (!$dryRun) {
                        $this->runExpiration($target['id']);
                    }
                    $entry['ran'] = !$dryRun;
                } elseif (!empty($target['exists'])) {
                    $t = ['path' => $target['path'], 'maxAge' => $target['maxAge'], 'patterns' => 'system_tmp' === $target['id'] ? self::SYSTEM_TMP_PATTERNS : null];
                    $r = $this->cleanDirectory($t, $dryRun);
                    $entry['files'] = $r['files'];
                    $entry['bytes'] = $r['bytes'];
                    $entry['errors'] = $r['errors'];
                }
            } catch (\Throwable $e) {
                $entry['errors'][] = $e->getMessage();
            }
            if (null !== $log) {
                $log(\sprintf('%s: %d files, %s%s', $target['label'], $entry['files'], \OCP\Util::humanFileSize($entry['bytes']), $entry['errors'] ? ' — '.implode('; ', $entry['errors']) : ''));
            }
            $report['files'] += $entry['files'];
            $report['bytes'] += $entry['bytes'];
            foreach ($entry['errors'] as $err) {
                $report['errors'][] = $target['label'].': '.$err;
            }
            $report['targets'][] = $entry;
        }
        $report['duration'] = round(microtime(true) - $start, 2);

        if (!$dryRun) {
            $this->appConfig->setValueString(Application::APPNAME, 'cleanup.last', json_encode($report));
            $history = json_decode($this->appConfig->getValueString(Application::APPNAME, 'cleanup.history', '[]'), true) ?: [];
            array_unshift($history, ['time' => $report['time'], 'files' => $report['files'], 'bytes' => $report['bytes'], 'duration' => $report['duration'], 'errors' => \count($report['errors'])]);
            $this->appConfig->setValueString(Application::APPNAME, 'cleanup.history', json_encode(\array_slice($history, 0, self::HISTORY_SIZE)));
            if ($report['files'] > 0 || $report['errors']) {
                $this->logger->info('Cleanup: removed '.$report['files'].' files ('.\OCP\Util::humanFileSize($report['bytes']).')'.($report['errors'] ? ', errors: '.implode('; ', $report['errors']) : ''));
            }
        }

        return $report;
    }

    /** @return array<string, mixed> everything the admin page shows */
    public function status(): array
    {
        return [
            'config' => $this->getConfig(),
            'targets' => $this->targets(true),
            'last' => json_decode($this->appConfig->getValueString(Application::APPNAME, 'cleanup.last', 'null'), true),
            'history' => json_decode($this->appConfig->getValueString(Application::APPNAME, 'cleanup.history', '[]'), true) ?: [],
            'job_interval_hours' => 6,
        ];
    }

    /**
     * Delete old files under a directory (never the directory itself, never following symlinks,
     * only files owned by this process' user).
     *
     * @param array{path:string, maxAge:int, patterns:?list<string>} $target
     *
     * @return array{files:int, bytes:int, errors:list<string>}
     */
    private function cleanDirectory(array $target, bool $dryRun): array
    {
        $root = $target['path'];
        $maxAge = max(60, (int) $target['maxAge']);
        $patterns = $target['patterns'];
        $now = time();
        $uid = \function_exists('posix_geteuid') ? posix_geteuid() : null;
        $result = ['files' => 0, 'bytes' => 0, 'errors' => []];

        // Only descend into what we may touch: in the system temp dir only top-level entries with
        // known Nextcloud-related names, and never into folders we cannot read (other services' private dirs).
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME);
        $filter = new \RecursiveCallbackFilterIterator($directory, static function ($current, $key, \RecursiveDirectoryIterator $iterator) use ($patterns): bool {
            $path = (string) $current;
            $name = basename($path);
            if (null !== $patterns && '' === $iterator->getSubPath() && !self::matchesAny($name, $patterns)) {
                return false;
            }
            if (!is_link($path) && is_dir($path) && !is_readable($path)) {
                return false;
            }

            return true;
        });
        $items = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::CHILD_FIRST);

        try {
        /** @var string $path */
        foreach ($items as $path) {
            $name = basename($path);
            if (\in_array($name, self::NEVER_DELETE, true)) {
                continue;
            }
            $stat = @lstat($path);
            if (false === $stat) {
                continue;
            }
            if (null !== $uid && $stat['uid'] !== $uid) {
                continue;
            }
            $age = $now - max($stat['mtime'], $stat['ctime']);
            $isLink = is_link($path);
            if ($isLink) {
                // dangling links (ImageMagick leaves them behind) go right away, others are left alone
                if (false !== @stat($path)) {
                    continue;
                }
            } elseif (is_dir($path)) {
                if ($age < $maxAge) {
                    continue;
                }
                $empty = !(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS))->valid();
                if (!$empty) {
                    continue;
                }
                if (!$dryRun && !@rmdir($path)) {
                    $result['errors'][] = 'could not remove folder '.$path;
                }
                continue;
            } elseif ($age < $maxAge) {
                continue;
            }
            $size = $isLink ? 0 : $stat['size'];
            if ($dryRun || @unlink($path)) {
                ++$result['files'];
                $result['bytes'] += $size;
            } else {
                $result['errors'][] = 'could not delete '.$path;
            }
        }
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ('' !== $pattern && preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:int,1:int} bytes, files */
    private function usage(string $dir): array
    {
        $bytes = 0;
        $files = 0;

        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    ++$files;
                    $bytes += (int) $file->getSize();
                }
                if ($files > 200000) {
                    break;
                }
            }
        } catch (\Throwable $e) {
        }

        return [$bytes, $files];
    }

    private function runExpiration(string $what): void
    {
        $class = 'trash' === $what ? '\OCA\Files_Trashbin\BackgroundJob\ExpireTrash' : '\OCA\Files_Versions\BackgroundJob\ExpireVersions';
        if (!class_exists($class)) {
            throw new \RuntimeException('app not enabled');
        }
        /** @var \OCP\BackgroundJob\Job $job */
        $job = \OC::$server->get($class);
        $job->start(\OC::$server->get(\OCP\BackgroundJob\IJobList::class));
    }
}
