<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductCode>
 */
class ProductCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductCode::class);
    }

    /** @param string[] $lookupKeys */
    public function findOneByLookupKeys(array $lookupKeys): ?ProductCode
    {
        if ($lookupKeys === []) {
            return null;
        }

        return $this->createQueryBuilder('code')
            ->where('code.lookupKey IN (:keys)')
            ->setParameter('keys', $lookupKeys)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
