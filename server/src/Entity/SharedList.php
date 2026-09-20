<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SharedListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A list of products someone chose to share, addressed only by its uuid.
 *
 * This is the first thing in the system that persists one person's
 * selection, so it is worth being explicit about what it is not: there is
 * no owner column, no device token, no address, and no link between two
 * lists made by the same person. The uuid is the only handle, it is
 * unguessable, and whoever holds it sees the list — nothing more.
 */
#[ORM\Entity(repositoryClass: SharedListRepository::class)]
#[ORM\Table(name: 'shared_list')]
class SharedList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $uuid;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * An opaque idempotency key supplied by whoever published the list.
     *
     * It exists so that pressing share twice does not leave a second list
     * behind. Deliberately *not* derived here: a digest the server could
     * compute would be the same for everybody who picked the same products,
     * and two strangers would silently end up sharing one address.
     *
     * The client hashes the selection together with a random secret it
     * keeps on the device and never sends. So the same person republishing
     * the same list matches, while nobody else can produce that value, and
     * the server cannot work out which lists came from one device, nor what
     * is in a list from its key.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true, unique: true)]
    private ?string $fingerprint = null;

    /** @var Collection<int, SharedListItem> */
    #[ORM\OneToMany(
        mappedBy: 'list',
        targetEntity: SharedListItem::class,
        cascade: ['persist'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $entries;

    public function __construct(string $uuid, ?string $fingerprint = null)
    {
        $this->uuid = $uuid;
        $this->fingerprint = $fingerprint;
        $this->createdAt = new \DateTimeImmutable();
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }



    /** @return Collection<int, SharedListItem> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addEntry(SharedListItem $entry): void
    {
        if ($this->entries->contains($entry)) {
            return;
        }
        $this->entries->add($entry);
        $entry->attachTo($this);
    }
}
