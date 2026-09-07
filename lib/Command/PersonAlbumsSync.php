<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\PersonAlbums;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PersonAlbumsSync extends Command
{
    public function __construct(private PersonAlbums $personAlbums)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('memories:person-albums-sync')
            ->setDescription('Add newly recognized photos to the automatic albums of people')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $added = $this->personAlbums->syncAll();
        $output->writeln("<info>{$added} photos added to person albums</info>");

        return 0;
    }
}
