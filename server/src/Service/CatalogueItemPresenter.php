<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CatalogueItem;
use App\Entity\ProductCode;

/**
 * Owns the wire shape. The only place that decides what a client sees.
 *
 * Scalar `barcode` / `qr_code` fields are emitted alongside the `codes`
 * array so older clients keep working while newer ones read the list.
 */
final class CatalogueItemPresenter
{
    public const SCHEMA = 'items.v4';

    /** @param CatalogueItem[] $items */
    public function collection(array $items): array
    {
        $data = [];

        for ($i = 0, $count = count($items); $i < $count; $i++) {
            $data[] = $this->one($items[$i]);
        }

        return [
            'schema' => self::SCHEMA,
            'count' => count($data),
            'data' => $data,
        ];
    }

    public function one(CatalogueItem $item): array
    {
        $codes = $item->getCodes()->toArray();
        $list = [];

        for ($i = 0, $count = count($codes); $i < $count; $i++) {
            $list[] = [
                'kind' => $codes[$i]->getKind(),
                'value' => $codes[$i]->getValue(),
            ];
        }

        return [
            'id' => $item->getId(),
            'uuid' => $item->getUuid(),
            'name' => $item->getName(),
            'barcode' => $this->firstOfKind($codes, ProductCode::KIND_BARCODE),
            'qr_code' => $this->firstOfKind($codes, ProductCode::KIND_QR),
            'codes' => $list,
            'created_at' => $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $item->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'deleted_at' => $item->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'withdrawn' => $item->isWithdrawn(),
        ];
    }

    /** @param ProductCode[] $codes */
    private function firstOfKind(array $codes, string $kind): ?string
    {
        for ($i = 0, $count = count($codes); $i < $count; $i++) {
            if ($codes[$i]->getKind() === $kind) {
                return $codes[$i]->getValue();
            }
        }

        return null;
    }
}
