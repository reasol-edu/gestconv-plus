<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\StudentExit;
use App\Entity\StudentPriorityPass;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * TEMPORAL — salidas de demostración para las capturas de la sección Salidas (manual y fichas). Se
 * ejecuta tras migrate + fixtures (--append) + tmp:seed-shots sobre una BD desechable. Las salidas se
 * fechan respecto a «ahora» = SHOTS_NOW (por defecto, el lunes 2026-10-05 09:30 UTC), el mismo reloj
 * que fija el router de capturas. No debe commitearse en src/Command: ver scripts/seed/README.md.
 */
#[AsCommand(name: 'tmp:seed-exits', description: 'Siembra salidas y un pase prioritario para las capturas (desechable).')]
final class TmpSeedExitsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $centre = $this->em->createQuery("SELECT c FROM App\\Entity\\EducationalCentre c WHERE c.name LIKE '%Ada Lovelace%'")->getSingleResult();
        $year   = $centre->getActiveAcademicYear();
        $now    = new \DateTimeImmutable(getenv('SHOTS_NOW') ?: '2026-10-05 09:30:00', new \DateTimeZone('UTC'));

        $teacher = fn (string $u) => $this->em->createQuery('SELECT t FROM App\Entity\Teacher t WHERE t.username = :u')->setParameter('u', $u)->getSingleResult();
        $roberto = $teacher('roberto.guerrero');
        $others  = $this->em->createQuery('SELECT t FROM App\Entity\Teacher t JOIN t.academicYears y WHERE y = :y AND t.username NOT IN (:ex) ORDER BY t.username')
            ->setParameter('y', $year)->setParameter('ex', ['roberto.guerrero', 'carmen.diaz', 'admin'])->setMaxResults(3)->getResult();

        $groups = $this->em->createQuery('SELECT g FROM App\Entity\Group g JOIN g.course c WHERE c.academicYear = :y ORDER BY g.name')->setParameter('y', $year)->getResult();
        $pairs  = [];
        foreach ($groups as $group) {
            $taken = 0;
            foreach ($group->getStudents() as $student) {
                $pairs[] = [$student, $group];
                if (++$taken === 2) {
                    break;
                }
            }
            if (count($pairs) >= 10) {
                break;
            }
        }

        $reasons = $this->em->createQuery('SELECT r FROM App\Entity\ExitReason r WHERE r.educationalCentre = :c ORDER BY r.position')->setParameter('c', $centre)->getResult();

        // Pase prioritario para el 4.º estudiante (sigue fuera: sale con el distintivo en las listas).
        $this->em->persist((new StudentPriorityPass())->setStudent($pairs[3][0])->setAcademicYear($year)->setGrantedBy($teacher('carmen.diaz'))
            ->setDescription('Diabetes tipo 1: puede ir a la enfermería a medirse la glucosa sin pedir permiso. Avisar a Jefatura si tarda más de 10 minutos.'));

        $spec = [
            // [pareja, autor, motivo, minutos fuera, observaciones, minutos hasta regresar|null]
            [0, $roberto,   0, 4,  null, null],
            [1, $others[0], 1, 13, null, null],
            [2, $others[1], 2, 24, 'Lleva un recado a Secretaría', null],
            [3, $others[2], 0, 36, null, null],
            [4, $others[0], 3, 95, null, 7],
            [5, $others[1], 0, 120, null, 12],
            [6, $roberto,   4, 150, 'Recoger los portátiles', 21],
            [7, $others[2], 1, 200, null, 5],
        ];
        foreach ($spec as [$i, $author, $r, $min, $obs, $back]) {
            $exit = (new StudentExit())
                ->setAcademicYear($year)->setStudent($pairs[$i][0])->setGroup($pairs[$i][1])
                ->setReason($reasons[$r])->setAuthorizedBy($author)->setObservations($obs)
                ->setExitedAt($now->modify("-{$min} minutes"));
            if ($back !== null) {
                $exit->markReturned($author, $now->modify('-' . ($min - $back) . ' minutes'));
            }
            $this->em->persist($exit);
        }
        $this->em->flush();

        foreach ($pairs as [$s, $g]) {
            $output->writeln($s->getName()->full() . ' · ' . $g->getName());
        }

        return Command::SUCCESS;
    }
}
