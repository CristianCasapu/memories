<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\AutoClips;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AutoClipsCommand extends Command
{
    public function __construct(private AutoClips $autoClips)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:auto-clips')
            ->setDescription('Queue clips of the recent events (for one user, or everyone who switched automatic clips on)')
            ->addArgument('user', InputArgument::OPTIONAL, 'user id')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'for the given user even when automatic clips are off')
            ->addOption('max', 'm', InputOption::VALUE_REQUIRED, 'at most this many clips', '3')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string) ($input->getArgument('user') ?? '');
        if ('' !== $uid) {
            if (!$input->getOption('force') && !$this->autoClips->enabledFor($uid)) {
                $output->writeln('Automatic clips are off for '.$uid.' (use --force)');

                return 0;
            }
            $n = $this->autoClips->runForUser($uid, (int) $input->getOption('max'));
        } else {
            $n = $this->autoClips->runAll();
        }
        $output->writeln($n.' clip(s) queued');

        return 0;
    }
}
