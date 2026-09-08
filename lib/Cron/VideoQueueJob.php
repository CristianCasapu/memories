<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\Service\VideoJobs;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Safety net: videos still waiting (a worker could not start) are made by cron, dead ones marked failed. */
final class VideoQueueJob extends TimedJob
{
    public function __construct(ITimeFactory $time, private VideoJobs $jobs)
    {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
        $this->setAllowParallelRuns(false);
    }

    #[\Override]
    protected function run(mixed $argument): void
    {
        $this->jobs->sweep(2);
    }
}
