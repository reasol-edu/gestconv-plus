<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityAttachment;
use App\Entity\ActivityLog;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\Communication;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\LocationOption;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SchoolEvent;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Repository\IncidentReportRepository;
use App\Service\SanctionTaskGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * TEMPORAL — datos de demostración para las capturas del manual, las fichas y la presentación
 * (scripts/capture-*.mjs). Se ejecuta tras migrate + fixtures (--append) sobre una BD desechable.
 * Las fechas se calculan alrededor de «hoy» (lunes 2026-10-05 por defecto) para que casen con el
 * reloj fijado en el router de capturas. No debe commitearse en src/Command: ver scripts/seed/.
 */
#[AsCommand(name: 'tmp:seed-shots', description: 'Siembra datos de demostración para las capturas (desechable).')]
final class TmpSeedShotsCommand extends Command
{
    private AcademicYear $year;
    private CommunicationMethod $method;
    /** @var list<IncidentBehavior> */
    private array $normal = [];
    private ?IncidentBehavior $serious = null;
    /** @var list<LocationOption> */
    private array $locations = [];
    private int $nextBehavior = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanctionTaskGenerator $taskGenerator,
        private readonly IncidentReportRepository $reports,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('today', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Fecha «de hoy» (lunes lectivo)', '2026-10-05');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $today  = new \DateTimeImmutable((string) $input->getOption('today'));
        $centre = $this->em->getRepository(EducationalCentre::class)->findOneBy(['name' => 'IES Ada Lovelace']);
        \assert($centre instanceof EducationalCentre);
        $year = $centre->getActiveAcademicYear();
        \assert($year instanceof AcademicYear);
        $this->year = $year;

        $roberto = $this->teacher('roberto.guerrero');
        $beatriz = $this->teacher('beatriz.alonso');
        $carmen  = $this->teacher('carmen.diaz');
        $pedro   = $this->teacher('pedro.fernandez');
        $maria   = $this->teacher('mariajose.alvarez');
        $rafael  = $this->teacher('rafael.exposito');

        $method = $this->em->getRepository(CommunicationMethod::class)->findOneBy(['educationalCentre' => $centre, 'name' => 'Correo electrónico'])
            ?? $this->em->getRepository(CommunicationMethod::class)->findOneBy(['educationalCentre' => $centre]);
        \assert($method instanceof CommunicationMethod);
        $this->method = $method;

        foreach ($this->em->getRepository(IncidentBehavior::class)->findBy(['educationalCentre' => $centre, 'active' => true]) as $b) {
            if ($b->isSerious()) {
                $this->serious ??= $b;
            } else {
                $this->normal[] = $b;
            }
        }
        $this->locations = array_values($this->em->getRepository(LocationOption::class)->findBy(['educationalCentre' => $centre]));

        $groupA   = $this->group('1ºESO A');
        $groupB   = $this->group('1ºESO B');
        $groupBac = $this->group('1ºBachillerato');
        $groupDaw = $this->group('1ºDAW');
        $group2   = $this->group('2ºESO A');

        // Beatriz cotutora de 1ºESO A (puede notificar sus partes y sanciones); Roberto y Pedro dan clase allí.
        $groupA->addTutor($beatriz);
        $groupA->addTeacher($roberto, 'Matemáticas');
        $groupA->addTeacher($pedro, 'Lengua Castellana');
        $groupBac->addTeacher($pedro, 'Lengua Castellana');
        $this->em->flush();

        $carla  = $this->student('Gil Cabrera', $groupA);
        $mario  = $this->students($groupA)[5];
        $marta  = $this->student('Rodríguez Navarro', $groupBac);
        $pablo  = $this->students($groupBac)[7];
        $others = [
            [$this->students($groupA)[9], $groupA], [$this->students($groupA)[12], $groupA],
            [$this->students($groupB)[2], $groupB], [$this->students($groupB)[8], $groupB],
            [$this->students($groupBac)[11], $groupBac], [$this->students($groupDaw)[3], $groupDaw],
            [$this->students($groupDaw)[6], $groupDaw], [$this->students($group2)[4], $group2],
        ];

        // ── Carla: sanción pendiente de notificar (R1, R2), dos partes sancionables (R3, R4) y un parte pendiente (R5)
        $r1 = $this->report($carla, $groupA, $roberto, '2026-09-21 10:05', 'El alumno se levantó de su sitio en varias ocasiones y molestó a sus compañeros.', true, $carmen, false);
        $r2 = $this->report($carla, $groupA, $beatriz, '2026-09-23 12:20', 'Insultó a un compañero durante el recreo y se negó a pedir disculpas.', true, $carmen, true);
        $r3 = $this->report($carla, $groupA, $roberto, '2026-09-28 09:10', 'Utilizó el teléfono móvil en clase tras ser advertido en dos ocasiones.', true, $carmen);
        $r4 = $this->report($carla, $groupA, $pedro, '2026-09-30 11:40', 'Amenazó a un compañero en el patio y le impidió el paso hacia la clase.', true, $carmen, true);
        $this->report($carla, $groupA, $beatriz, '2026-10-02 08:55', 'Llegó tarde por tercera vez esta semana y entró sin permiso al aula.', false);

        $s1 = $this->sanction($carla, $groupA, $carmen, [$r1, $r2], 'Se aplica una amonestación escrita y la realización de tareas en el aula de convivencia por la reiteración de conductas contrarias.', '2026-10-08', '2026-10-09', 'Aula de convivencia');
        $this->em->flush();
        $this->taskGenerator->generateFor($s1);

        // ── Mario: sanción ya notificada y vigente hoy (para guardias, calendario y tareas de roberto)
        $m1 = $this->report($mario, $groupA, $roberto, '2026-09-24 10:30', 'Agredió verbalmente a un profesor de guardia.', true, $carmen, true);
        $m2 = $this->report($mario, $groupA, $pedro, '2026-09-25 13:15', 'Se enfrentó al profesorado y abandonó el aula sin permiso.', true, $carmen);
        $s2 = $this->sanction($mario, $groupA, $carmen, [$m1, $m2], 'Expulsión del centro durante tres días lectivos con realización de tareas en casa.', '2026-10-05', '2026-10-07', 'Expulsión 3 días');
        $this->em->flush();
        $this->notifySanction($s2, $carmen);
        $tasks = $this->taskGenerator->generateFor($s2);
        foreach ($tasks as $task) {
            if ($task->getGroupTeacher()->getTeacher() === $beatriz) {
                $task->setDescription('Resumen del tema 3 y ejercicios 1 a 8.')->setCompletedAt(new \DateTimeImmutable('2026-10-02 12:00'));
            }
        }

        // ── Marta (1ºBach): dos partes notificados y sancionables + uno pendiente; sanción antigua con seguimiento
        $this->report($marta, $groupBac, $roberto, '2026-09-22 09:50', 'Interrumpió la explicación de forma reiterada.', true, $carmen);
        $this->report($marta, $groupBac, $pedro, '2026-09-29 12:05', 'Faltó al respeto a la profesora delante del grupo.', true, $carmen, true);
        $this->report($marta, $groupBac, $roberto, '2026-10-01 10:00', 'Usó el móvil durante un examen.', false);
        $p1 = $this->report($pablo, $groupBac, $roberto, '2026-09-15 11:00', 'Deterioro intencionado del mobiliario del aula.', true, $carmen, true);
        $p2 = $this->report($pablo, $groupBac, $pedro, '2026-09-16 12:30', 'Reincidió en el mismo comportamiento tras ser amonestado.', true, $carmen);
        $s3 = $this->sanction($pablo, $groupBac, $carmen, [$p1, $p2], 'Reparación del daño causado y tarea de convivencia durante una semana.', '2026-09-21', '2026-09-25', 'Tareas de convivencia', '2026-09-18 11:00');
        $s3->setMeasuresEffective(true)->setFamilyClaimed(false)->setRegisteredInSeneca(true);
        $this->em->flush();
        $this->notifySanction($s3, $carmen);

        // ── Relleno: más partes (notificados y pendientes) para que listados y estadísticas tengan cuerpo
        $descs = [
            'No respetó el turno de palabra y provocó la risa del grupo.',
            'Salió del aula sin permiso durante la clase.',
            'Se negó a realizar la actividad y desafió al profesor.',
            'Escribió en la mesa y en la pared del aula.',
            'Empujó a un compañero en el pasillo entre clases.',
            'Estuvo hablando durante toda la sesión pese a los avisos.',
            'Llegó con retraso y sin justificante en varias ocasiones.',
            'Comió en clase y dejó el aula sucia.',
        ];
        $days = ['2026-09-17 09:05', '2026-09-18 10:20', '2026-09-22 13:00', '2026-09-24 08:45', '2026-09-25 11:55', '2026-09-29 09:30', '2026-09-30 12:40', '2026-10-01 13:10'];
        foreach ($others as $i => [$student, $group]) {
            $teacher = [$roberto, $beatriz, $pedro, $rafael][$i % 4];
            $this->report($student, $group, $teacher, $days[$i], $descs[$i], $i % 3 !== 0, $carmen, $i === 2);
        }
        $this->report($this->students($groupA)[20], $groupA, $roberto, '2026-10-02 10:15', 'Se negó a guardar el móvil y contestó de malas maneras.', false);
        $this->report($this->students($groupB)[14], $groupB, $beatriz, '2026-10-02 12:25', 'Molestó de forma continuada a un compañero.', false);

        // ── Notas diarias: dos retrasos previos de Carla (la tercera, en la captura, alcanza el umbral)
        $retraso = $this->em->getRepository(DailyNoteType::class)->findOneBy(['educationalCentre' => $centre, 'name' => 'Retraso a primera hora']);
        $movil   = $this->em->getRepository(DailyNoteType::class)->findOneBy(['educationalCentre' => $centre, 'name' => 'Uso del móvil']);
        \assert($retraso instanceof DailyNoteType && $movil instanceof DailyNoteType);
        foreach ([[$carla, $retraso, '2026-09-30 08:20'], [$carla, $retraso, '2026-10-02 08:25'], [$this->students($groupB)[2], $movil, '2026-10-01 11:00']] as [$s, $type, $at]) {
            $group = $s === $carla ? $groupA : $groupB;
            $this->em->persist((new DailyNote())->setAcademicYear($this->year)->setStudent($s)->setGroup($group)->setType($type)
                ->setRegisteredBy($roberto)->setOccurredAt(new \DateTimeImmutable($at))->setActive(true));
        }

        // ── Guardias y ausencias del día (lunes) con actividad y adjunto
        $slots = [];
        foreach ([['1ª hora', '08:15', '09:15', [$roberto, $carmen]], ['2ª hora', '09:15', '10:15', [$roberto, $rafael]], ['3ª hora', '10:15', '11:15', [$carmen, $rafael]]] as [$name, $from, $to, $guards]) {
            $slot = (new TimeSlot())->setAcademicYear($this->year)->setName($name)->setDayOfWeek((int) $today->format('N') - 1)
                ->setStartTime(new \DateTimeImmutable("2000-01-01 $from"))->setEndTime(new \DateTimeImmutable("2000-01-01 $to"));
            foreach ($guards as $g) {
                $slot->addGuard($g);
            }
            $this->em->persist($slot);
            $slots[] = $slot;
        }
        $this->em->flush();

        $pedroGt = $this->groupTeacher($groupA, $pedro);
        $mariaGt = $this->groupTeacher($groupBac, $pedro);
        $abs1    = (new Absence())->setTeacher($pedro)->setAcademicYear($this->year)->setStartDate($today)->setEndDate($today);
        $this->em->persist($abs1);
        $act1 = (new Activity())->setAbsence($abs1)->setDate($today)->setTimeSlot($slots[0])
            ->setDescription('<p>Ejercicios del tema 4, páginas 32 y 33. Corregir en la próxima clase.</p>')->addSubject($pedroGt);
        $abs1->addActivity($act1);
        $this->em->persist($act1);
        $this->em->flush();
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 200 200]/Parent 2 0 R>>endobj\ntrailer<</Root 1 0 R>>";
        $this->em->persist(new ActivityAttachment($act1, 'ejercicios-tema-4.pdf', 'application/pdf', \strlen($pdf), $pdf));
        $act2 = (new Activity())->setAbsence($abs1)->setDate($today)->setTimeSlot($slots[1])
            ->setDescription('<p>Lectura del capítulo 2 y resumen de media página.</p>')->addSubject($mariaGt);
        $abs1->addActivity($act2);
        $this->em->persist($act2);
        $abs2 = (new Absence())->setTeacher($maria)->setAcademicYear($this->year)->setStartDate($today)->setEndDate($today->modify('+1 day'));
        $this->em->persist($abs2);

        // ── Eventos del centro (calendario)
        foreach ([
            ['Reunión de claustro', '2026-10-07', '16:00', '17:30', true, []],
            ['Excursión a la Sierra de Aracena', '2026-10-15', '08:30', '15:00', false, [$groupA, $groupB]],
            ['Elecciones de delegados', '2026-10-20', '11:30', '12:30', true, []],
        ] as [$name, $date, $from, $to, $general, $groups]) {
            $event = (new SchoolEvent())->setAcademicYear($this->year)->setName($name)->setDate(new \DateTimeImmutable($date))
                ->setStartTime(new \DateTimeImmutable("2000-01-01 $from"))->setEndTime(new \DateTimeImmutable("2000-01-01 $to"))->setGeneral($general);
            foreach ($groups as $g) {
                $event->addGroup($g);
            }
            $this->em->persist($event);
        }

        // ── Registro de actividad: una entrada de cada forma que muestra la lista legible
        $admin = $this->teacher('admin');
        $log = [
            ['session.login', $roberto, null],
            ['incident_report.created', $roberto, ['entityId' => $r3->getId()->toRfc4122(), 'studentId' => $carla->getId()->toRfc4122(), 'groupId' => $groupA->getId()->toRfc4122(), 'number' => $r3->getNumber()]],
            ['incident_report.updated', $beatriz, ['entityId' => $r2->getId()->toRfc4122(), 'changes' => ['description' => ['before' => 'Insultó a un compañero.', 'after' => 'Insultó a un compañero durante el recreo.'], 'expelledFromClass' => ['before' => false, 'after' => true]]]],
            ['communication.registered', $carmen, ['entityId' => $r3->getId()->toRfc4122(), 'reportId' => $r3->getId()->toRfc4122(), 'method' => 'Correo electrónico', 'result' => 'notified']],
            ['sanction.created', $carmen, ['entityId' => $s2->getId()->toRfc4122(), 'studentId' => $mario->getId()->toRfc4122(), 'groupId' => $groupA->getId()->toRfc4122(), 'reportIds' => [$m1->getId()->toRfc4122(), $m2->getId()->toRfc4122()], 'taskCount' => 3]],
            ['student.imported', $carmen, ['created' => 12, 'updated' => 3, 'skipped' => 1]],
            ['teacher.created', $admin, ['entityId' => $pedro->getId()->toRfc4122(), 'username' => 'pedro.fernandez']],
            ['absence.created', $pedro, ['entityId' => $abs1->getId()->toRfc4122()]],
        ];
        $minute = 0;
        foreach ($log as [$action, $user, $data]) {
            $this->em->persist(new ActivityLog($today->modify('-1 day')->setTime(18, 30 + $minute++), '192.168.1.' . (20 + $minute), $action, $user, null, $this->year, $data));
        }

        $this->em->flush();
        $output->writeln('Datos de demostración sembrados.');

        return Command::SUCCESS;
    }

    private function teacher(string $username): Teacher
    {
        $t = $this->em->getRepository(Teacher::class)->findOneBy(['username' => $username]);
        \assert($t instanceof Teacher, $username);

        return $t;
    }

    private function group(string $name): Group
    {
        foreach ($this->em->getRepository(Group::class)->findBy(['name' => $name]) as $g) {
            if ($g->getCourse()->getAcademicYear() === $this->year) {
                return $g;
            }
        }
        throw new \RuntimeException("Grupo no encontrado: $name");
    }

    /** @return list<Student> */
    private function students(Group $group): array
    {
        $students = $group->getStudents()->toArray();
        usort($students, static fn (Student $a, Student $b): int => [$a->getName()->getLastName(), $a->getName()->getFirstName()] <=> [$b->getName()->getLastName(), $b->getName()->getFirstName()]);

        return $students;
    }

    private function student(string $lastName, Group $group): Student
    {
        foreach ($this->students($group) as $s) {
            if (str_starts_with($s->getName()->getLastName(), $lastName)) {
                return $s;
            }
        }
        throw new \RuntimeException("Estudiante no encontrado: $lastName");
    }

    private function groupTeacher(Group $group, Teacher $teacher): GroupTeacher
    {
        foreach ($group->getTeacherAssignments() as $gt) {
            if ($gt->getTeacher() === $teacher) {
                return $gt;
            }
        }
        throw new \RuntimeException('GroupTeacher no encontrado');
    }

    private function report(Student $student, Group $group, Teacher $by, string $at, string $text, bool $notified, ?Teacher $notifier = null, bool $serious = false): IncidentReport
    {
        $behavior = $serious && $this->serious !== null ? $this->serious : $this->normal[$this->nextBehavior++ % \count($this->normal)];
        $report   = (new IncidentReport())
            ->setAcademicYear($this->year)
            ->setNumber($this->reports->nextNumberForYear($this->year))
            ->setStudent($student)->setGroup($group)->setRegisteredBy($by)
            ->setOccurredAt(new \DateTimeImmutable($at))
            ->setDescription('<p>' . $text . '</p>')
            ->setExpelledFromClass($serious);
        if ($this->locations !== []) {
            $report->setLocation($this->locations[$this->nextBehavior % \count($this->locations)]);
        }
        $report->addBehavior($behavior);
        $this->em->persist($report);
        $this->em->flush();

        if ($notified) {
            $comm = Communication::forIncidentReport($report, $this->method, $notifier ?? $by, new \DateTimeImmutable($at . ' +1 day'), CommunicationResult::Notified, 'Se informa a la familia por correo electrónico.');
            $this->em->persist($comm);
            $report->setNotifiedCommunication($comm);
            $this->em->flush();
        }

        return $report;
    }

    /** @param list<IncidentReport> $reports */
    private function sanction(Student $student, Group $group, Teacher $by, array $reports, string $details, ?string $from, ?string $to, ?string $label = null, string $createdAt = '2026-10-02 12:30'): Sanction
    {
        $measure = null;
        foreach ($this->em->getRepository(SanctionMeasure::class)->findBy(['educationalCentre' => $this->year->getEducationalCentre()]) as $candidate) {
            if ($candidate->hasDateRange() === ($from !== null)) {
                $measure = $candidate;
                break;
            }
        }
        $s = (new Sanction())->setAcademicYear($this->year)->setStudent($student)->setGroup($group)->setRegisteredBy($by)
            ->setDetails('<p>' . $details . '</p>')->setNoMeasureApplied($measure === null);
        if ($measure instanceof SanctionMeasure) {
            $s->addMeasure($measure);
        }
        if ($from !== null) {
            $s->setEffectiveFrom(new \DateTimeImmutable($from))->setEffectiveTo(new \DateTimeImmutable((string) $to))->setCalendarLabel($label);
        }
        (new \ReflectionProperty($s, 'createdAt'))->setValue($s, new \DateTimeImmutable($createdAt));
        $this->em->persist($s);
        foreach ($reports as $r) {
            $r->setSanction($s);
            $s->getReports()->add($r);
        }
        $this->em->flush();

        return $s;
    }

    private function notifySanction(Sanction $s, Teacher $by): void
    {
        $comm = Communication::forSanction($s, $this->method, $by, new \DateTimeImmutable('2026-10-02 12:00'), CommunicationResult::Notified, 'Se informa a la familia de la sanción.');
        $this->em->persist($comm);
        $s->setNotifiedCommunication($comm);
        $this->em->flush();
    }
}
