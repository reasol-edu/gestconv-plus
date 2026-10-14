<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Student;
use App\Entity\StudentPriorityPass;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StudentPriorityPass>
 */
class StudentPriorityPassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudentPriorityPass::class);
    }

    public function findOneByStudentAndYear(Student $student, AcademicYear $year): ?StudentPriorityPass
    {
        $result = $this->createQueryBuilder('p')
            ->where('p.student = :student')
            ->andWhere('p.academicYear = :year')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof StudentPriorityPass ? $result : null;
    }

    /**
     * Pases del curso para un conjunto de estudiantes, indexados por id (RFC 4122) de estudiante.
     * Una sola consulta, para poder pintar el distintivo en cualquier listado sin N+1.
     *
     * @param iterable<Student> $students
     * @return array<string, StudentPriorityPass>
     */
    public function findByStudentsIndexed(iterable $students, AcademicYear $year): array
    {
        // Cada UUID se enlaza por separado con el tipo 'uuid': un único parámetro IN (:students)
        // saltaría la conversión de UuidType y no encontraría filas (ver SanctionTaskRepository).
        $qb           = $this->createQueryBuilder('p');
        $placeholders = [];
        $i            = 0;
        foreach ($students as $student) {
            $placeholders[] = ":student{$i}";
            $qb->setParameter("student{$i}", $student->getId(), 'uuid');
            ++$i;
        }
        if ($placeholders === []) {
            return [];
        }

        /** @var list<StudentPriorityPass> $passes */
        $passes = $qb
            ->where('p.academicYear = :year')
            ->andWhere('IDENTITY(p.student) IN (' . implode(', ', $placeholders) . ')')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($passes as $pass) {
            $indexed[$pass->getStudent()->getId()->toRfc4122()] = $pass;
        }

        return $indexed;
    }
}
