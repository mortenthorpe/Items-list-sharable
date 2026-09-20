<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CatalogueItem;
use App\Entity\ItemLookupKey;
use App\Entity\ProductCode;
use App\Repository\CatalogueItemRepository;
use App\Service\CodeNormaliser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:seed-catalogue', description: 'Load the starting products. Safe to re-run.')]
final class SeedCatalogueCommand extends Command
{
    /** name, barcode, qr (either may be null) */
    private const PRODUCTS = [
        ['Band-aid-wide',             '5701120030018', 'https://id.example.org/01/05701120030018'],
        ['Band-aid Waterproof',       '5701120030087', 'https://id.example.org/01/05701120030087'],
        ['Band-aid Cotton',           '5701120030155', null],
        ['Coloplast Stomi Bag',       '5701120030223', 'https://id.example.org/01/05701120030223'],
        ['Coloplast Compeed Blister', '5701120030292', 'https://id.example.org/01/05701120030292'],
        ['Coloplast Compeed Comfort', '5701120030360', 'https://id.example.org/01/05701120030360'],
        ['Coloplast Compeed Aquatic', '5701120030438', null],
        ['IbuProfen Pills',           '5701120030506', 'https://id.example.org/01/05701120030506'],
        ['Aspirine',                  null,            'https://id.example.org/01/05701120030575'],
    ];

    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly CodeNormaliser $normaliser,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $added = 0;
        $skipped = 0;

        for ($i = 0, $count = count(self::PRODUCTS); $i < $count; $i++) {
            [$name, $barcode, $qr] = self::PRODUCTS[$i];

            $values = [];
            if ($barcode !== null) {
                $values[] = $barcode;
            }
            if ($qr !== null) {
                $values[] = $qr;
            }

            $keys = $this->normaliser->canonicalAll($values);

            if ($this->items->findOneByLookupKeys($keys) !== null) {
                ++$skipped;
                continue;
            }

            $item = new CatalogueItem(Uuid::v4()->toRfc4122(), $name, CatalogueItem::ORIGIN_SEED);

            if ($barcode !== null) {
                $item->addCode(new ProductCode(ProductCode::KIND_BARCODE, $barcode));
            }

            if ($qr !== null) {
                $item->addCode(new ProductCode(ProductCode::KIND_QR, $qr));
            }

            // A barcode and its digital link share one canonical key.
            for ($k = 0, $keyCount = count($keys); $k < $keyCount; $k++) {
                $item->addLookupKey(new ItemLookupKey($keys[$k]));
            }

            $this->entityManager->persist($item);
            ++$added;
        }

        $this->entityManager->flush();
        $io->success(sprintf('%d added, %d already present.', $added, $skipped));

        return Command::SUCCESS;
    }
}
