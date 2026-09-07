<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\Service\Cleanup;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Housekeeping of temporary files (see Service\Cleanup), every 6 hours when enabled. */
final class CleanupJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private Cleanup $cleanup,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(6 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    #[\Override]
    protected function run(mixed $argument): void
    {
        try {
            $this->cleanup->run(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Cleanup failed', ['exception' => $e]);
        }
    }
}
