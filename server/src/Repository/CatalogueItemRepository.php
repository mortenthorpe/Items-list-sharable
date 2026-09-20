<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogueItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CatalogueItem>
 */
class CatalogueItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogueItem::class);
    }

    /**
     * The whole catalogue with its codes in one query, ordered by item
     * number so clients see a stable list.
     *
     * Withdrawn items are included by default and marked as such. A client
     * that never receives the tombstone cannot tell "withdrawn" from "I
     * have never heard of this", so hiding them here would break the very
     * thing the tombstone exists for.
     *
     * @return CatalogueItem[]
     */
    public function findAllWithCodes(bool $includeWithdrawn = true): array
    {
        $builder = $this->createQueryBuilder('item')
            ->addSelect('code')
            ->leftJoin('item.codes', 'code')
            ->orderBy('item.id', 'ASC');

        if (!$includeWithdrawn) {
            $builder->where('item.deletedAt IS NULL');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * Items altered after a given instant, for a client refreshing a copy.
     *
     * @return CatalogueItem[]
     */
    public function findChangedSince(\DateTimeImmutable $moment): array
    {
        return $this->createQueryBuilder('item')
            ->addSelect('code')
            ->leftJoin('item.codes', 'code')
            ->where('item.updatedAt > :moment')
            ->setParameter('moment', $moment)
            ->orderBy('item.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Items for a set of numbers, keyed by number so a caller can rebuild
     * a list that repeats an item or orders it its own way.
     *
     * @param  int[] $numbers
     * @return array<int, CatalogueItem>
     */
    public function findByNumbers(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('item')
            ->addSelect('code')
            ->leftJoin('item.codes', 'code')
            ->where('item.id IN (:numbers)')
            ->setParameter('numbers', array_values(array_unique($numbers)))
            ->getQuery()
            ->getResult();

        $byNumber = [];

        for ($i = 0, $count = count($rows); $i < $count; $i++) {
            $byNumber[$rows[$i]->getId()] = $rows[$i];
        }

        return $byNumber;
    }

    /** @param string[] $lookupKeys */
    public function findOneByLookupKeys(array $lookupKeys): ?CatalogueItem
    {
        if ($lookupKeys === []) {
            return null;
        }

        return $this->createQueryBuilder('item')
            ->addSelect('code')
            ->leftJoin('item.codes', 'code')
            ->innerJoin('item.lookupKeys', 'match')
            ->where('match.lookupKey IN (:keys)')
            ->setParameter('keys', $lookupKeys)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(CatalogueItem $item, bool $flush = true): void
    {
        $this->getEntityManager()->persist($item);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
