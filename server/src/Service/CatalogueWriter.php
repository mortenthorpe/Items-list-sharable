<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NewItemRequest;
use App\Entity\CatalogueItem;
use App\Entity\ItemLookupKey;
use App\Entity\ProductCode;
use App\Repository\CatalogueItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Accepting a contribution.
 *
 * Idempotent by product code: submitting a code the catalogue already knows
 * returns the existing item instead of creating a near-duplicate. That also
 * means repeat submissions leave no trace of having happened twice.
 */
final class CatalogueWriter
{
    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly CodeNormaliser $normaliser,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{item: CatalogueItem, created: bool, restored: bool} */
    public function submit(NewItemRequest $request): array
    {
        $values = [];
        $codes = $request->codes;

        for ($i = 0, $count = count($codes); $i < $count; $i++) {
            $values[] = $codes[$i]->value;
        }

        $keys = $this->normaliser->canonicalAll($values);
        $existing = $this->items->findOneByLookupKeys($keys);

        if ($existing !== null) {
            /*
             * Someone has the product in their hand, so it is evidently
             * still in the world. A withdrawal is a statement about the
             * catalogue, not a fact about reality, and the contribution is
             * fresh evidence against it: bring the item back rather than
             * leaving a live product marked as gone, or worse, creating a
             * second row for it.
             */
            $revived = $existing->isWithdrawn();

            if ($revived) {
                $existing->restore();
                $this->entityManager->flush();
            }

            return ['item' => $existing, 'created' => false, 'restored' => $revived];
        }

        $item = new CatalogueItem(
            Uuid::v4()->toRfc4122(),
            trim($request->name),
            CatalogueItem::ORIGIN_CONTRIBUTED,
        );

        $seenValues = [];
        $seenKeys = [];

        for ($i = 0, $count = count($codes); $i < $count; $i++) {
            $value = trim($codes[$i]->value);
            $key = $this->normaliser->canonical($value);

            if ($value === '' || $key === '') {
                continue;
            }

            // Every distinct printed code is kept...
            if (!in_array($value, $seenValues, true)) {
                $seenValues[] = $value;
                $item->addCode(new ProductCode($codes[$i]->kind, $value));
            }

            // ...but a canonical key is recorded once, because a barcode and
            // the digital link wrapping it are the same identifier.
            if (!in_array($key, $seenKeys, true)) {
                $seenKeys[] = $key;
                $item->addLookupKey(new ItemLookupKey($key));
            }
        }

        if ($item->getCodes()->count() === 0) {
            throw new \InvalidArgumentException('No usable code in the submission');
        }

        $this->items->save($item);
        $this->entityManager->refresh($item);

        return ['item' => $item, 'created' => true, 'restored' => false];
    }
}
