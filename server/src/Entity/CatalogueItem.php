<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CatalogueItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product in the shared catalogue.
 *
 * Note what is absent: there is no owner, no contributor, no submitter IP,
 * no device token. An item contributed by scanning is indistinguishable
 * from a seeded one apart from `origin`, which records how it arrived and
 * not who brought it.
 */
#[ORM\Entity(repositoryClass: CatalogueItemRepository::class)]
#[ORM\Table(name: 'catalogue_item')]
class CatalogueItem
{
    public const ORIGIN_SEED = 'seed';
    public const ORIGIN_CONTRIBUTED = 'contributed';

    /** The integer the clients put in their QR export. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $uuid;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $origin;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Moves whenever anything a client copies changes: the name, or the
     * codes. Clients compare it against what they last copied, so it has to
     * be touched by every write that alters those, or their caches go stale
     * silently.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * When the product was withdrawn, or null while it is current.
     *
     * Withdrawal is a tombstone, never a deletion. Rows are referenced by
     * lists people have already shared and by copies sitting on their
     * devices; removing one would silently rewrite what they saved. A
     * withdrawn item stops being offered to add, and nothing else about it
     * changes.
     */
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** @var Collection<int, ProductCode> */
    #[ORM\OneToMany(
        mappedBy: 'item',
        targetEntity: ProductCode::class,
        cascade: ['persist'],
        orphanRemoval: true
    )]
    private Collection $codes;

    /** @var Collection<int, ItemLookupKey> */
    #[ORM\OneToMany(
        mappedBy: 'item',
        targetEntity: ItemLookupKey::class,
        cascade: ['persist'],
        orphanRemoval: true
    )]
    private Collection $lookupKeys;

    public function __construct(string $uuid, string $name, string $origin)
    {
        $this->uuid = $uuid;
        $this->name = $name;
        $this->origin = $origin;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->codes = new ArrayCollection();
        $this->lookupKeys = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isWithdrawn(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Take the product out of circulation. Touching updatedAt matters as
     * much as setting deletedAt: it is what tells every client holding a
     * copy that there is something new to learn about this item.
     */
    public function withdraw(): void
    {
        if ($this->deletedAt !== null) {
            return;
        }
        $this->deletedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function restore(): void
    {
        if ($this->deletedAt === null) {
            return;
        }
        $this->deletedAt = null;
        $this->touch();
    }

    /** Record that something a client mirrors has changed. */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function rename(string $name): void
    {
        if ($name === $this->name) {
            return;
        }
        $this->name = $name;
        $this->touch();
    }

    /** @return Collection<int, ProductCode> */
    public function getCodes(): Collection
    {
        return $this->codes;
    }

    public function addCode(ProductCode $code): void
    {
        if ($this->codes->contains($code)) {
            return;
        }
        $this->codes->add($code);
        $code->attachTo($this);
        $this->touch();
    }

    /** @return Collection<int, ItemLookupKey> */
    public function getLookupKeys(): Collection
    {
        return $this->lookupKeys;
    }

    public function addLookupKey(ItemLookupKey $key): void
    {
        if ($this->lookupKeys->contains($key)) {
            return;
        }
        $this->lookupKeys->add($key);
        $key->attachTo($this);
    }

    /** @return ProductCode[] */
    public function codesOfKind(string $kind): array
    {
        $found = [];
        $all = $this->codes->toArray();

        for ($i = 0, $count = count($all); $i < $count; $i++) {
            if ($all[$i]->getKind() === $kind) {
                $found[] = $all[$i];
            }
        }

        return $found;
    }
}
