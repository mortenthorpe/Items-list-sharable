<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SharedListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared lists accumulate. Nothing expires on its own — a QR code printed
 * on paper should not stop working because a cron job decided so — but
 * keeping them forever is a choice, not a default. Run this when it suits
 * the retention you want.
 */
#[AsCommand(name: 'app:purge-lists', description: 'Delete shared lists older than a given age.')]
final class PurgeListsCommand extends Command
{
    public function __construct(
        private readonly SharedListRepository $lists,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'e.g. "30 days"', '90 days');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $age = (string) $input->getOption('older-than');

        try {
            $cutoff = new \DateTimeImmutable('-' . $age);
        } catch (\Exception $exception) {
            $io->error('Could not read "' . $age . '" as an age.');

            return Command::FAILURE;
        }

        $stale = $this->lists->findOlderThan($cutoff);

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('%d lists are older than %s.', count($stale), $age));

            return Command::SUCCESS;
        }

        for ($i = 0, $count = count($stale); $i < $count; $i++) {
            $this->entityManager->remove($stale[$i]);
        }

        $this->entityManager->flush();
        $io->success(sprintf('Deleted %d lists older than %s.', count($stale), $age));

        return Command::SUCCESS;
    }
}
