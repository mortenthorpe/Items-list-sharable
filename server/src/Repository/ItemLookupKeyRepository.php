<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ItemLookupKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ItemLookupKey>
 */
class ItemLookupKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemLookupKey::class);
    }
}
