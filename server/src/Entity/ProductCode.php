<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One code as it is physically printed on a product, kept verbatim so a
 * client can match on exactly what it scanned.
 *
 * Equality between spellings is NOT expressed here: a product's barcode and
 * the GS1 Digital Link in its QR code reduce to the same number, so two rows
 * of this table legitimately share a canonical form. That mapping lives in
 * ItemLookupKey instead, which is where uniqueness is enforced.
 */
#[ORM\Entity(repositoryClass: ProductCodeRepository::class)]
#[ORM\Table(name: 'product_code')]
#[ORM\UniqueConstraint(name: 'uniq_item_value', columns: ['item_id', 'value'])]
class ProductCode
{
    public const KIND_BARCODE = 'barcode';
    public const KIND_QR = 'qr';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CatalogueItem::class, inversedBy: 'codes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CatalogueItem $item = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $kind;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $value;

    public function __construct(string $kind, string $value)
    {
        $this->kind = $kind === self::KIND_QR ? self::KIND_QR : self::KIND_BARCODE;
        $this->value = $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getValue(): string
    {
        return $this->value;
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
