<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\Events;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class EventsRebuild extends Command
{
    public function __construct(private Events $events)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:events-rebuild')
            ->setDescription('Recompute the automatic events (shooting sessions) of one or all users')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = $input->getOption('user');
        $n = null !== $uid ? $this->events->rebuild((string) $uid) : $this->events->rebuildAll();
        $output->writeln("<info>{$n} events</info>");

        return 0;
    }
}
