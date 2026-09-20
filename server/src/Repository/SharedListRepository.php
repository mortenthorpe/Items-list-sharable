<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharedList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SharedList>
 */
class SharedListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedList::class);
    }

    /** The list and everything needed to render it, in one query. */
    public function findOneByUuidWithItems(string $uuid): ?SharedList
    {
        return $this->createQueryBuilder('list')
            ->addSelect('entry', 'item', 'code')
            ->leftJoin('list.entries', 'entry')
            ->leftJoin('entry.item', 'item')
            ->leftJoin('item.codes', 'code')
            ->where('list.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->orderBy('entry.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByFingerprint(string $fingerprint): ?SharedList
    {
        return $this->createQueryBuilder('list')
            ->where('list.fingerprint = :fingerprint')
            ->setParameter('fingerprint', $fingerprint)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return SharedList[] */
    public function findOlderThan(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('list')
            ->where('list.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    public function save(SharedList $list, bool $flush = true): void
    {
        $this->getEntityManager()->persist($list);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
