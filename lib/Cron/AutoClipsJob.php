<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\Service\AutoClips;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Once a day: clips of the recent events, for the people who asked for them. */
final class AutoClipsJob extends TimedJob
{
    public function __construct(ITimeFactory $time, private AutoClips $autoClips, private LoggerInterface $logger)
    {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    #[\Override]
    protected function run(mixed $argument): void
    {
        try {
            $n = $this->autoClips->runAll();
            $this->logger->debug('Auto clips: '.$n.' queued');
        } catch (\Throwable $e) {
            $this->logger->warning('Auto clips failed', ['exception' => $e]);
        }
    }
}
