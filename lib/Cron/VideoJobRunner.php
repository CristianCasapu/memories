<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\Service\VideoJobs;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** Makes one queued video (the worker started right away usually gets there first). */
final class VideoJobRunner extends QueuedJob
{
    public function __construct(ITimeFactory $time, private VideoJobs $jobs)
    {
        parent::__construct($time);
        $this->setAllowParallelRuns(false);
    }

    #[\Override]
    protected function run(mixed $argument): void
    {
        $id = (int) (\is_array($argument) ? ($argument['id'] ?? 0) : 0);
        if ($id > 0) {
            $this->jobs->run($id);
        }
    }
}
