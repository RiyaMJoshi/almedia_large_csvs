<?php

namespace App\Repository;

use App\Entity\MetaTable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MetaTable>
 *
 * @method MetaTable|null find($id, $lockMode = null, $lockVersion = null)
 * @method MetaTable|null findOneBy(array $criteria, array $orderBy = null)
 * @method MetaTable[]    findAll()
 * @method MetaTable[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MetaTableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetaTable::class);
    }

    public function getColumnNames(string $filename): array // string $filename
    {
        return $this->createQueryBuilder('mt')
            ->addSelect('mt.columns')
            ->andWhere('mt.filename = :filename')
            ->setParameter('filename', $filename)
            ->getQuery()
            ->getResult()
        ; 
    }

    public function createOrDropDynamicTable($sql){

        $em = $this->getEntityManager();

        $stmt = $em->getConnection()->prepare($sql);
        $stmt->executeQuery();

    }

    public function addDataToTable($sql){

        $em = $this->getEntityManager();

        $stmt = $em->getConnection()->prepare($sql);
        $stmt->executeQuery();

    }
    
    public function getUpdatedcsv($sql){

        $em = $this->getEntityManager();

        $stmt = $em->getConnection()->prepare($sql);
        $conn=$stmt->executeQuery()->fetchAllAssociative();
        return $conn;        
        
    }

    // Check if table exists or not (As the Table gets deleted once clicked on Export)
    public function table_exists(string $table): bool
    {
        $em = $this->getEntityManager();

        $table_exists_sql = <<<eof
        SELECT COUNT(*) AS 'Count'
        FROM information_schema.tables 
        WHERE table_schema = 'csvManager' 
        AND table_name = '$table';
        eof;
        $stmt = $em->getConnection()->prepare($table_exists_sql);
        $conn=$stmt->executeQuery()->fetchAllAssociative()[0]['Count'];

        // dd($conn);
        if (!$conn) {
            return false;
        }
        return true;        
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(MetaTable $entity, bool $flush = true): void
    {
        $this->_em->persist($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(MetaTable $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return MetaTable[] Returns an array of MetaTable objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?MetaTable
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
