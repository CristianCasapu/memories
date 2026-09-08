<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\VideoJobs;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Worker for one video job (started detached by the app) or the whole queue. */
final class VideoRun extends Command
{
    public function __construct(private VideoJobs $jobs)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:video-run')
            ->setDescription('Make the queued video with the given id, or every queued video when no id is given')
            ->addArgument('id', InputArgument::OPTIONAL, 'job id')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) ($input->getArgument('id') ?? 0);
        if ($id > 0) {
            $job = $this->jobs->run($id);
            if (null === $job) {
                $output->writeln('<error>No such job</error>');

                return 1;
            }
            $output->writeln('Job '.$id.': '.$job->getStatus().('' !== (string) $job->getError() ? ' — '.$job->getError() : '').('' !== $job->getResultName() ? ' — '.$job->getResultFolder().'/'.$job->getResultName() : ''));

            return 0;
        }
        $n = $this->jobs->sweep(1000);
        $output->writeln($n.' video(s) made');

        return 0;
    }
}
