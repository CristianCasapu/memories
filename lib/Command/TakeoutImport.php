<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OC\Files\SetupManager;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Service\Takeout;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-index the photos that have a Google Takeout sidecar, so what the sidecar says lands in the
 * database (date, location, description, people, favourite) — for photos indexed before the
 * sidecars were uploaded, or before this feature existed. The files are not modified.
 */
final class TakeoutImport extends Command
{
    private int $done = 0;

    public function __construct(
        private IRootFolder $rootFolder,
        private SetupManager $setupManager,
        private IUserManager $userManager,
        private TimelineWrite $timelineWrite,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:takeout-import')
            ->setDescription('Import Google Takeout JSON sidecars into the index (date, location, description, people, favourites) without touching the files')
            ->addArgument('user', InputArgument::OPTIONAL, 'only this user')
            ->addOption('folder', 'f', InputOption::VALUE_REQUIRED, 'only this folder (relative to the user\'s files)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Takeout::enabled()) {
            $output->writeln('<comment>Takeout import is switched off (Administration › Memories › Media Indexing); importing anyway for this run.</comment>');
        }
        $uid = (string) ($input->getArgument('user') ?? '');
        $folderOpt = (string) ($input->getOption('folder') ?? '');
        if ('' !== $uid) {
            $user = $this->userManager->get($uid);
            if (null === $user) {
                $output->writeln("<error>User {$uid} does not exist</error>");

                return 1;
            }
            $this->runUser($user, $folderOpt, $output);
        } else {
            $this->userManager->callForSeenUsers(function (IUser $user) use ($folderOpt, $output): void {
                $this->runUser($user, $folderOpt, $output);
            });
        }
        $output->writeln("Done: {$this->done} photo(s) re-indexed with their sidecar");

        return 0;
    }

    private function runUser(IUser $user, string $folderOpt, OutputInterface $output): void
    {
        $uid = $user->getUID();
        $this->setupManager->tearDown();
        $this->setupManager->setupForUser($user);
        $folder = $this->rootFolder->getUserFolder($uid);
        if ('' !== $folderOpt) {
            try {
                $node = $folder->get($folderOpt);
                if (!$node instanceof Folder) {
                    throw new \Exception('not a folder');
                }
                $folder = $node;
            } catch (\Throwable) {
                $output->writeln("<error>{$uid}: folder {$folderOpt} does not exist</error>");

                return;
            }
        }
        $output->writeln("{$uid}: scanning {$folder->getPath()}");
        Takeout::walk($folder, function (File $photo) use ($output): void {
            try {
                $this->timelineWrite->processFile($photo, true, true);
                ++$this->done;
                if (0 === $this->done % 100) {
                    $output->writeln("  {$this->done} photos");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>{$photo->getPath()}: {$e->getMessage()}</error>");
            }
        });
    }
}
