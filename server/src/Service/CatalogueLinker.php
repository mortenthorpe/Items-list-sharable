<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CatalogueItem;
use App\Entity\ItemLookupKey;
use App\Entity\ProductCode;
use App\Repository\CatalogueItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Attaches a further code to a product that already exists.
 *
 * This is the only way two unrelated codes on one package can ever be
 * connected. A barcode and a GS1 Digital Link share a number, so the
 * normaliser alone links them — but a QR carrying a marketing URL has no
 * computable relationship to the EAN printed beside it. That connection can
 * only be *observed*, by someone scanning both off the same box, and this is
 * where that observation is recorded.
 */
final class CatalogueLinker
{
    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly CodeNormaliser $normaliser,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public const ATTACHED = 'attached';
    public const ALREADY_PRESENT = 'already_present';

    /**
     * @return array{item: CatalogueItem, outcome: string}
     *
     * @throws \DomainException      when the code belongs to another product
     * @throws \InvalidArgumentException when the code is unusable
     */
    public function attach(CatalogueItem $item, string $kind, string $value): array
    {
        // Reading a code off a pack is evidence the product exists, so a
        // withdrawn item being linked to comes back into circulation.
        $item->restore();

        $value = trim($value);
        $key = $this->normaliser->canonical($value);

        if ($value === '' || $key === '') {
            throw new \InvalidArgumentException('That code cannot be used as an identifier');
        }

        $owner = $this->items->findOneByLookupKeys([$key]);

        if ($owner !== null && $owner->getId() !== $item->getId()) {
            // Two products claiming one code would make scans ambiguous.
            // Refuse rather than silently merge or steal the code.
            throw new \DomainException(sprintf(
                'That code already identifies "%s"',
                $owner->getName()
            ));
        }

        if ($owner !== null) {
            $this->addPrintedCode($item, $kind, $value);
            $item->touch();
            $this->entityManager->flush();

            return ['item' => $item, 'outcome' => self::ALREADY_PRESENT];
        }

        $this->addPrintedCode($item, $kind, $value);
        $item->addLookupKey(new ItemLookupKey($key));
        $item->touch();
        $this->entityManager->flush();

        return ['item' => $item, 'outcome' => self::ATTACHED];
    }

    private function addPrintedCode(CatalogueItem $item, string $kind, string $value): void
    {
        $existing = $item->getCodes()->toArray();

        for ($i = 0, $count = count($existing); $i < $count; $i++) {
            if ($existing[$i]->getValue() === $value) {
                return;
            }
        }

        $item->addCode(new ProductCode($kind, $value));
    }
}
