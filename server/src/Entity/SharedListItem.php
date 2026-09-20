<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SharedListItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One product on a shared list, in the order it was sent.
 *
 * A product may appear more than once: the client's list records events,
 * not a set, so two of the same item is a meaningful thing to convey.
 */
#[ORM\Entity(repositoryClass: SharedListItemRepository::class)]
#[ORM\Table(name: 'shared_list_item')]
#[ORM\Index(name: 'idx_list', columns: ['list_id'])]
class SharedListItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SharedList::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SharedList $list = null;

    /*
     * RESTRICT, not CASCADE. Withdrawal is soft, so this never fires in
     * normal use — but if someone ever hard-deletes a catalogue row, the
     * database should refuse rather than quietly empty out lists people
     * have already shared.
     */
    #[ORM\ManyToOne(targetEntity: CatalogueItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?CatalogueItem $item = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position;

    public function __construct(CatalogueItem $item, int $position)
    {
        $this->item = $item;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ?CatalogueItem
    {
        return $this->item;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function attachTo(SharedList $list): void
    {
        $this->list = $list;
    }
}
