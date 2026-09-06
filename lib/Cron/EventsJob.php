<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\Service\Events;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds the automatic events of every user (cheap: one pass over the index).
 */
final class EventsJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private Events $events,
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
            $n = $this->events->rebuildAll();
            $this->logger->debug("Events: {$n} events rebuilt");
        } catch (\Throwable $e) {
            $this->logger->warning('Events rebuild failed', ['exception' => $e]);
        }
    }
}
