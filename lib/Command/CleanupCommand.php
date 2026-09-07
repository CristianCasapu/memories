<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\Cleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CleanupCommand extends Command
{
    public function __construct(private Cleanup $cleanup)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:cleanup')
            ->setDescription('Remove old temporary files (Nextcloud temp dir, system /tmp leftovers, go-vod cache; optionally expire trash and versions) — same as the cleanup job / admin page')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be removed')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run even if the cleanup is disabled in the admin settings')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->cleanup->run((bool) $input->getOption('dry-run'), (bool) $input->getOption('force'), static fn (string $line) => $output->writeln('  '.$line));
        if ($report['skipped']) {
            $output->writeln('<comment>Cleanup is disabled in the admin settings (use --force).</comment>');

            return 0;
        }
        $output->writeln(\sprintf('<info>%s%d files, %s in %.1f s%s</info>', $report['dry_run'] ? '[dry run] ' : '', $report['files'], \OCP\Util::humanFileSize($report['bytes']), $report['duration'], $report['errors'] ? ', '.\count($report['errors']).' errors' : ''));

        return $report['errors'] ? 1 : 0;
    }
}
