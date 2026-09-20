<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ItemLookupKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A canonical identifier that resolves to exactly one product.
 *
 * Several printed codes collapse to one key — the EAN-13 on the box, the
 * UPC-A spelling of the same GTIN, the digital link inside the QR — and all
 * of them must lead to the same item. The unique index is the guarantee
 * that a scan can never be ambiguous, and it is what makes contributing an
 * already-known product idempotent rather than duplicating it.
 */
#[ORM\Entity(repositoryClass: ItemLookupKeyRepository::class)]
#[ORM\Table(name: 'item_lookup_key')]
#[ORM\UniqueConstraint(name: 'uniq_lookup_key', columns: ['lookup_key'])]
class ItemLookupKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CatalogueItem::class, inversedBy: 'lookupKeys')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CatalogueItem $item = null;

    #[ORM\Column(name: 'lookup_key', type: Types::STRING, length: 255)]
    private string $lookupKey;

    public function __construct(string $lookupKey)
    {
        $this->lookupKey = $lookupKey;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLookupKey(): string
    {
        return $this->lookupKey;
    }

    public function getItem(): ?CatalogueItem
    {
        return $this->item;
    }

    public function attachTo(CatalogueItem $item): void
    {
        $this->item = $item;
    }
}
