<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CatalogueItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Take a product out of circulation, or put it back.
 *
 * There is deliberately no command that deletes one. Catalogue rows are
 * referenced by lists people have shared and copies on their devices;
 * withdrawal is the supported way to retire a product.
 */
#[AsCommand(name: 'app:withdraw-item', description: 'Withdraw a catalogue item, or restore one.')]
final class WithdrawItemCommand extends Command
{
    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The item number');
        $this->addOption('restore', null, InputOption::VALUE_NONE, 'Put it back into circulation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $item = $this->items->find((int) $input->getArgument('id'));

        if ($item === null) {
            $io->error('No item with that number.');

            return Command::FAILURE;
        }

        if ($input->getOption('restore')) {
            $item->restore();
            $this->entityManager->flush();
            $io->success(sprintf('#%d "%s" is available again.', $item->getId(), $item->getName()));

            return Command::SUCCESS;
        }

        $item->withdraw();
        $this->entityManager->flush();
        $io->success(sprintf(
            '#%d "%s" withdrawn. Existing lists keep it, marked as withdrawn.',
            $item->getId(),
            $item->getName()
        ));

        return Command::SUCCESS;
    }
}
