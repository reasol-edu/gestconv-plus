<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueAttachment;
use App\Entity\IssueChange;
use App\Entity\IssueObservation;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\ResourceBooking;
use App\Entity\Teacher;
use App\Repository\IssueRepository;
use App\Service\IssueAttachmentFactory;
use App\Service\IssueDeadlines;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * TEMPORAL — datos de demostración del módulo de incidencias para las capturas del manual
 * (scripts/capture-issues-shots.mjs). Se ejecuta tras migrate + fixtures (--append) + `tmp:seed-shots` sobre una BD
 * desechable (necesita las reservas de `tmp:seed-shots`). Fechas alrededor de «hoy» (lunes 2026-10-05 por defecto).
 * No debe commitearse en src/Command: ver scripts/seed/.
 */
#[AsCommand(name: 'tmp:seed-issues', description: 'Siembra tipos e incidencias de demostración para las capturas (desechable).')]
final class TmpSeedIssuesCommand extends Command
{
    private EducationalCentre $centre;
    private \DateTimeImmutable $today;
    private int $number = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueRepository $issues,
        private readonly IssueDeadlines $deadlines,
        private readonly IssueAttachmentFactory $attachments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('today', null, InputOption::VALUE_REQUIRED, 'Fecha «de hoy» (lunes lectivo)', '2026-10-05');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->today = new \DateTimeImmutable((string) $input->getOption('today') . ' 09:30:00');
        $centre      = $this->em->getRepository(EducationalCentre::class)->findOneBy(['name' => 'IES Ada Lovelace']);
        \assert($centre instanceof EducationalCentre);
        $this->centre = $centre;
        $year         = $centre->getActiveAcademicYear();
        \assert($year !== null);

        $roberto = $this->teacher('roberto.guerrero');
        $beatriz = $this->teacher('beatriz.alonso');
        $pedro   = $this->teacher('pedro.fernandez');
        $maria   = $this->teacher('mariajose.alvarez');
        $rafael  = $this->teacher('rafael.exposito');

        // Los plazos de «Alta» y «Crítica» con fechas recomendadas para que se vean en las capturas.
        $prio = [];
        foreach ($this->em->getRepository(IssuePriority::class)->findBy(['educationalCentre' => $centre]) as $p) {
            $prio[$p->getName()] = $p;
        }
        $prio['Alta']->setRecommendedDays(3)->setMaxDays(7);
        $prio['Crítica']->setRecommendedDays(0)->setMaxDays(2);
        $prio['Media']->setRecommendedDays(5)->setMaxDays(15);

        // ── Tipos ─────────────────────────────────────────────────────────────────────────────────────────
        $type = function (string $name, ?IssueType $parent, Teacher ...$managers) use ($centre): IssueType {
            $t = (new IssueType())->setEducationalCentre($centre)->setName($name)->setParent($parent);
            foreach ($managers as $m) {
                $t->addManager($m);
            }
            $this->em->persist($t);

            return $t;
        };
        // Beatriz es la responsable de Mantenimiento (y sus tipos); Rafael, de Informática. Los dos son del curso activo.
        $mantenimiento = $type('Mantenimiento', null, $beatriz);
        $electricidad  = $type('Electricidad', $mantenimiento);
        $fontaneria    = $type('Fontanería', $mantenimiento);
        $climatizacion = $type('Climatización', $mantenimiento);
        $informatica   = $type('Informática', null, $rafael);
        $equipos       = $type('Equipos del aula', $informatica);
        $red           = $type('Red y wifi', $informatica);
        $limpieza      = $type('Limpieza', null);
        $reservas      = $type('Reservas de recursos', null);
        $dependencias  = $type('Dependencias', $reservas, $beatriz);
        $this->em->flush();

        // Dependencias del centro (el catálogo «Ubicaciones»), en su propia categoría.
        $category = (new \App\Entity\LocationOptionCategory())->setEducationalCentre($centre)->setName('Edificio principal')->setPosition(9);
        $this->em->persist($category);
        $places = [];
        foreach (['Aula 12', 'Aula 3', 'Aula de música', 'Baños de la planta 1', 'Pasillo de la planta 2', 'Pasillo de la planta baja', 'Gimnasio'] as $i => $name) {
            $places[$name] = (new \App\Entity\LocationOption())->setEducationalCentre($centre)->setCategory($category)->setName($name)->setPosition($i);
            $this->em->persist($places[$name]);
        }
        $this->em->flush();

        // ── Incidencias ───────────────────────────────────────────────────────────────────────────────────
        $one = $this->issue($year, $electricidad, $roberto, -9, '<p>Se ha fundido la <strong>luz del aula 12</strong>: los fluorescentes de la fila de la ventana no encienden.</p>', $places['Aula 12']);
        $this->prioritize($one, $beatriz, $prio['Alta'], -8);
        $this->status($one, $beatriz, IssueStatus::InProgress, -8);
        $this->observe($one, $beatriz, 'Pedido el material, llega el jueves.', -6);
        $this->observe($one, $roberto, 'Gracias. Mientras tanto damos la clase en el aula 14.', -6);
        $this->attach($one, $roberto, 'aula-12-fluorescentes.png', -9);

        $booking = $this->em->getRepository(ResourceBooking::class)->findOneBy(['teacher' => $roberto], ['date' => 'ASC']);
        $two     = $this->issue($year, $equipos, $roberto, 0, '<p>Tres de los portátiles del aula no arrancan y el proyector se apaga a los diez minutos de encenderlo.</p>', null, $booking);

        $three = $this->issue($year, $fontaneria, $pedro, -14, '<p>Gotea el grifo del baño de la planta 1 y el suelo está siempre mojado.</p>', $places['Baños de la planta 1']);
        $this->prioritize($three, $beatriz, $prio['Crítica'], -13);
        $this->status($three, $beatriz, IssueStatus::InProgress, -13);

        $four = $this->issue($year, $climatizacion, $maria, -3, '<p>El aire acondicionado del aula 3 no enfría.</p>', $places['Aula 3']);
        $this->prioritize($four, $beatriz, $prio['Media'], -2);
        $this->status($four, $beatriz, IssueStatus::InProgress, -2);

        $five = $this->issue($year, $red, $pedro, -20, '<p>No hay wifi en el aula de música.</p>', $places['Aula de música']);
        $this->prioritize($five, $rafael, $prio['Alta'], -19);
        $this->observe($five, $rafael, 'Reiniciado el punto de acceso y cambiado de canal. Funciona.', -17);
        $this->status($five, $rafael, IssueStatus::Solved, -17);

        $six = $this->issue($year, $limpieza, $rafael, -11, '<p>El suelo del pasillo de la planta baja está sucio desde el lunes.</p>', $places['Pasillo de la planta baja']);
        $this->observe($six, $this->teacher('carmen.diaz'), 'Es el servicio de limpieza de la empresa: se lo trasladamos por otra vía.', -10);
        $this->status($six, $this->teacher('carmen.diaz'), IssueStatus::NotApplicable, -10);

        $seven = $this->issue($year, $red, $maria, -18, '<p>Sin wifi en el aula de música.</p>', $places['Aula de música']);
        $seven->setDuplicateOf($five);
        $this->status($seven, $rafael, IssueStatus::Duplicate, -17);

        $eight = $this->issue($year, $electricidad, $pedro, -1, '<p>Se funde la luz del pasillo de la planta 2.</p>', $places['Pasillo de la planta 2']);

        $this->em->flush();
        $output->writeln(sprintf('%d incidencias sembradas.', $this->number));
        unset($two, $eight, $dependencias, $reservas, $limpieza);

        return Command::SUCCESS;
    }

    private function teacher(string $username): Teacher
    {
        $t = $this->em->getRepository(Teacher::class)->findOneBy(['username' => $username]);
        \assert($t instanceof Teacher, $username);

        return $t;
    }

    private function at(int $dayOffset, string $time = '10:15:00'): \DateTimeImmutable
    {
        return $this->today->modify(sprintf('%+d days', $dayOffset))->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
    }

    private function issue(\App\Entity\AcademicYear $year, IssueType $type, Teacher $reporter, int $dayOffset, string $description, ?\App\Entity\LocationOption $location, ?ResourceBooking $booking = null): Issue
    {
        $at    = $this->at($dayOffset, '09:10:00');
        $issue = (new Issue($at))
            ->setEducationalCentre($this->centre)->setAcademicYear($year)->setNumber(++$this->number)
            ->setType($type)->setLocation($booking === null ? $location : null)->setBooking($booking)
            ->setReporter($reporter)->setDescription($description)->setStatus(IssueStatus::Open);
        $this->em->persist($issue);
        $this->change($issue, $reporter, 'created', null, $type->getPath(), $at);

        return $issue;
    }

    private function prioritize(Issue $issue, Teacher $by, IssuePriority $priority, int $dayOffset): void
    {
        $at = $this->at($dayOffset);
        $this->change($issue, $by, 'priority', null, $priority->getName(), $at);
        $issue->setPriority($priority)->touch($at);
        $this->deadlines->apply($issue, $priority, $at);
    }

    private function status(Issue $issue, Teacher $by, IssueStatus $to, int $dayOffset): void
    {
        $at = $this->at($dayOffset, '12:40:00');
        $this->change($issue, $by, 'status', $issue->getStatus()->value, $to->value, $at);
        $issue->setStatus($to)->touch($at);
        if ($to->isClosed()) {
            $issue->setClosedAt($at);
        }
    }

    private function observe(Issue $issue, Teacher $by, string $text, int $dayOffset): void
    {
        $at = $this->at($dayOffset, '13:05:00');
        $o  = new IssueObservation($issue, $by, $text, $at);
        $issue->addObservation($o);
        $issue->touch($at);
        $this->em->persist($o);
    }

    private function change(Issue $issue, ?Teacher $by, string $field, ?string $old, ?string $new, \DateTimeImmutable $at): void
    {
        $c = new IssueChange($issue, $by, $field, $old, $new, $at);
        $issue->addChange($c);
        $this->em->persist($c);
    }

    private function attach(Issue $issue, Teacher $by, string $filename, int $dayOffset): void
    {
        $img = imagecreatetruecolor(800, 600);
        $bg  = imagecolorallocate($img, 232, 238, 247);
        imagefilledrectangle($img, 0, 0, 800, 600, $bg);
        $dark = imagecolorallocate($img, 71, 85, 105);
        imagefilledrectangle($img, 80, 120, 720, 200, $dark);
        $light = imagecolorallocate($img, 250, 204, 21);
        imagefilledellipse($img, 400, 330, 220, 220, $light);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $at         = $this->at($dayOffset, '09:12:00');
        $attachment = new IssueAttachment($issue, $by, $filename, 'image/png', strlen($png), $png, $this->attachments->thumbnail($png), $at);
        $issue->addAttachment($attachment);
        $this->em->persist($attachment);
        $this->change($issue, $by, 'attachment', null, $filename, $at);

        $doc = 'Presupuesto del electricista: 3 fluorescentes LED, 42 €.';
        $d   = new IssueAttachment($issue, $by, 'presupuesto.txt', 'text/plain', strlen($doc), $doc, null, $at);
        $issue->addAttachment($d);
        $this->em->persist($d);
    }
}
