<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Cron\WeeklyRecapJob;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WeeklyRecap extends Command
{
    public function __construct(private WeeklyRecapJob $job, private IUserManager $userManager)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:weekly-recap')
            ->setDescription('Send the "Your memories from this week" notification now (all users, or one)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this user')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Send even below the minimum number of photos or when the user disabled it')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $uid = $input->getOption('user');
        $users = null !== $uid ? [(string) $uid] : [];
        if (null === $uid) {
            $this->userManager->callForSeenUsers(function ($user) use (&$users): void { $users[] = $user->getUID(); });
        }
        foreach ($users as $u) {
            $n = $this->job->notifyUser($u, $force);
            $output->writeln($n > 0 ? "<info>{$u}: notification sent ({$n} photos)</info>" : "{$u}: nothing to send");
        }

        return 0;
    }
}
