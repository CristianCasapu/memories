<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\Service\PersonAlbums;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps person albums in sync with newly recognized photos.
 */
final class PersonAlbumsJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private PersonAlbums $personAlbums,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    #[\Override]
    protected function run(mixed $argument): void
    {
        try {
            $added = $this->personAlbums->syncAll();
            if ($added > 0) {
                $this->logger->info("Person albums: added {$added} photos");
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Person albums sync failed', ['exception' => $e]);
        }
    }
}
