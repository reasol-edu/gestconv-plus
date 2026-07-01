<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\IncidentBehaviorSeeder;
use App\Service\SanctionMeasureSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Connection $connection,
        private readonly IncidentBehaviorSeeder $behaviorSeeder,
        private readonly SanctionMeasureSeeder $sanctionMeasureSeeder,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->wipeDatabase();
        $manager->clear();

        $ejemploHash = $this->passwordHasher->hashPassword(
            new Teacher(new PersonName('tmp', 'tmp')),
            'ejemplo',
        );

        $admin = new Teacher(new PersonName('Admin', 'User'));
        $admin->setUsername('admin');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin'));
        $admin->setAdmin(true);
        $manager->persist($admin);

        $aTeachers = $this->makeAdaLovelaceTeachers($manager, $ejemploHash);
        $mTeachers = $this->makeMonterrubioTeachers($manager, $ejemploHash);
        $manager->flush();

        [$aEsoPyears, $aBachPyears, $aDawPyears] = $this->buildAdaLovelace($manager, $aTeachers);
        [$mEsoPyears, $mBachPyears]              = $this->buildMonterrubio($manager, $mTeachers);

        // IES Ada Lovelace: dos grupos por curso en ESO, uno en Bach. y uno en DAW.
        $this->buildGroups($manager, $aEsoPyears, $aTeachers, 'AA', 28, ' A');
        $this->buildGroups($manager, $aEsoPyears, $aTeachers, 'AB', 27, ' B');
        $this->buildGroups($manager, $aBachPyears, $aTeachers, 'AC', 22);
        $this->buildGroups($manager, $aDawPyears,  $aTeachers, 'AD', 24);

        // IES Monterrubio: dos grupos por curso en ESO, uno en Bach.
        $this->buildGroups($manager, $mEsoPyears, $mTeachers, 'MA', 26, ' A');
        $this->buildGroups($manager, $mEsoPyears, $mTeachers, 'MB', 26, ' B');
        $this->buildGroups($manager, $mBachPyears, $mTeachers, 'MC', 20);

        $manager->flush();
    }

    // ── Limpieza de base de datos ─────────────────────────────────────────────

    private function wipeDatabase(): void
    {
        $conn    = $this->connection;
        $isMysql = str_contains(get_class($conn->getDatabasePlatform()), 'MySQL')
                || str_contains(get_class($conn->getDatabasePlatform()), 'MariaDB');

        $q = $isMysql
            ? fn(string $t) => '`' . $t . '`'
            : fn(string $t) => '"' . $t . '"';

        $conn->executeStatement('UPDATE educational_centre SET active_academic_year_id = NULL');

        $stmts = [
            'DELETE FROM ' . $q('incident_report_behavior'),
            'DELETE FROM ' . $q('incident_report'),
            'DELETE FROM ' . $q('sanction_sanction_measure'),
            'DELETE FROM ' . $q('sanction'),
            'DELETE FROM ' . $q('incident_behavior'),
            'DELETE FROM ' . $q('student_groups'),
            'DELETE FROM ' . $q('student'),
            'DELETE FROM ' . $q('group_tutor'),
            'DELETE FROM ' . $q('group_teacher'),
            'DELETE FROM ' . $q('group'),

            'DELETE FROM ' . $q('programme_year'),
            'DELETE FROM ' . $q('programme'),
            'DELETE FROM ' . $q('teacher_academic_year'),
            'DELETE FROM ' . $q('educational_centre_admins'),
            'DELETE FROM ' . $q('academic_year'),
            'DELETE FROM ' . $q('teacher_setting_value'),
            'DELETE FROM ' . $q('centre_setting_value'),
            'DELETE FROM ' . $q('global_setting_value'),
            'DELETE FROM ' . $q('sanction_measure'),
            'DELETE FROM ' . $q('sanction_measure_category'),
            'DELETE FROM ' . $q('educational_centre'),
            'DELETE FROM ' . $q('teacher'),
        ];

        foreach ($stmts as $sql) {
            $conn->executeStatement($sql);
        }
    }

    // ── Creación de docentes ──────────────────────────────────────────────────

    /** @return array<string, Teacher> */
    private function makeAdaLovelaceTeachers(ObjectManager $manager, string $passwordHash): array
    {
        $data = [
            // 0-1: equipo directivo (admins de centro)
            ['rafael.exposito',   'Rafael',       'Expósito Moreno'],
            ['carmen.diaz',       'Carmen',       'Díaz Jiménez'],
            // 2-6: coordinadores
            ['francisco.molina',  'Francisco',    'Molina Ruiz'],
            ['isabel.lozano',     'Isabel',       'Lozano Herrera'],
            ['maria.garcia',      'María Dolores','García Fernández'],
            ['diego.romero',      'Diego',        'Romero Vega'],
            ['manuel.perez',      'Manuel',       'Pérez Blanco'],
            // 7+: docentes de grupo (tutores y co-docentes)
            ['roberto.guerrero',  'Roberto',      'Guerrero Campos'],
            ['beatriz.alonso',    'Beatriz',      'Alonso Serrano'],
            ['rodrigo.fuentes',   'Rodrigo',      'Fuentes Parra'],
            ['elena.caballero',   'Elena',        'Caballero Ruiz'],
            ['julio.medina',      'Julio',        'Medina Torres'],
            ['sofia.delgado',     'Sofía',        'Delgado Iglesias'],
            ['marcos.herrero',    'Marcos',       'Herrero Vidal'],
            ['alberto.cabrera',   'Alberto',      'Cabrera García'],
            ['nuria.lopez',       'Nuria',        'López Morales'],
            ['javier.ortega',     'Javier',       'Ortega Bravo'],
            ['anabelen.castro',   'Ana Belén',    'Castro Fuentes'],
            ['tomas.vazquez',     'Tomás',        'Vázquez Acosta'],
            ['rosamaria.serrano', 'Rosa María',   'Serrano Díaz'],
            ['fernando.ibanez',   'Fernando',     'Ibáñez Cano'],
            ['marta.ramos',       'Marta',        'Ramos Palacios'],
            ['sergio.gallego',    'Sergio',       'Gallego Nieto'],
            ['veronica.mora',     'Verónica',     'Mora Espinosa'],
            ['pablo.aguilar',     'Pablo',        'Aguilar Blanco'],
            ['concepcion.munoz',  'Concepción',   'Muñoz Aranda'],
            ['alvaro.suarez',     'Álvaro',       'Suárez Paredes'],
            ['patricia.rubio',    'Patricia',     'Rubio Fernández'],
            ['luis.carrasco',     'Luis',         'Carrasco Reyes'],
            ['sandra.dominguez',  'Sandra',       'Domínguez Orozco'],
        ];

        return $this->persistTeachers($manager, $data, $passwordHash, isGlobalAdmin: ['rafael.exposito']);
    }

    /** @return array<string, Teacher> */
    private function makeMonterrubioTeachers(ObjectManager $manager, string $passwordHash): array
    {
        $data = [
            ['mariajose.alvarez',    'María José',    'Álvarez García'],
            ['pedro.fernandez',      'Pedro Antonio', 'Fernández Rubio'],
            ['rosario.soto',         'Rosario',       'Soto Merino'],
            ['dolores.reyes',        'Dolores',       'Reyes Álvarez'],
            ['antonia.guzman',       'Antonia',       'Guzmán Osuna'],
            ['ignacio.crespo',       'Ignacio',       'Crespo Leal'],
            ['piedad.torres',        'Piedad',        'Torres Velázquez'],
            ['vicente.roldan',       'Vicente',       'Roldán Camacho'],
            ['carmenrosa.marin',     'Carmen Rosa',   'Marín Espejo'],
            ['josefa.naranjo',       'Josefa',        'Naranjo Hidalgo'],
            ['remedios.calvo',       'Remedios',      'Calvo Durán'],
            ['bartolome.morales',    'Bartolomé',     'Morales Cabello'],
            ['francisca.giron',      'Francisca',     'Girón Padilla'],
            ['sebastian.lara',       'Sebastián',     'Lara Nieto'],
            ['encarnacion.baena',    'Encarnación',   'Baena Vilches'],
            ['manuela.criado',       'Manuela',       'Criado Arroyo'],
            ['demetrio.gallardo',    'Demetrio',      'Gallardo Cruz'],
            ['amelia.fuentes',       'Amelia',        'Fuentes Olea'],
            ['isidoro.bueno',        'Isidoro',       'Bueno Salas'],
            ['remedios.ortiz',       'Remedios',      'Ortiz Pedrera'],
            ['alfonso.serrano',      'Alfonso',       'Serrano Rico'],
            ['montserrat.cobo',      'Montserrat',    'Cobo Rivas'],
            ['gonzalo.torres',       'Gonzalo',       'Torres Jurado'],
            ['esperanza.ruiz',       'Esperanza',     'Ruiz Calero'],
            ['horacio.lopez',        'Horacio',       'López Bravo'],
            ['natividad.moreno',     'Natividad',     'Moreno Navarro'],
            ['dionisio.garcia',      'Dionisio',      'García Blanco'],
            ['rosalia.campos',       'Rosalía',       'Campos Vega'],
            ['teodoro.herrero',      'Teodoro',       'Herrero Reina'],
            ['milagros.jimenez',     'Milagros',      'Jiménez Villar'],
        ];

        return $this->persistTeachers($manager, $data, $passwordHash, isGlobalAdmin: ['mariajose.alvarez']);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $data
     * @param string[] $isGlobalAdmin
     * @return array<string, Teacher>
     */
    private function persistTeachers(ObjectManager $manager, array $data, string $passwordHash, array $isGlobalAdmin = []): array
    {
        $teachers = [];
        foreach ($data as [$username, $first, $last]) {
            $t = new Teacher(new PersonName($first, $last));
            $t->setUsername($username);
            $t->setPassword($passwordHash);
            if (in_array($username, $isGlobalAdmin, true)) {
                $t->setAdmin(true);
            }
            $manager->persist($t);
            $teachers[$username] = $t;
        }
        return $teachers;
    }

    // ── Estructura académica ──────────────────────────────────────────────────

    /**
     * Devuelve [esoPyears, bachPyears, dawPyears].
     *
     * @param array<string, Teacher> $teachers
     * @return array{ProgrammeYear[], ProgrammeYear[], ProgrammeYear[]}
     */
    private function buildAdaLovelace(ObjectManager $manager, array $teachers): array
    {
        $centre = (new EducationalCentre())
            ->setCode('23006123')
            ->setName('IES Ada Lovelace')
            ->setCity('Linares');

        $year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $manager->persist($centre);
        $manager->persist($year);

        $this->behaviorSeeder->seedForCentre($centre);
        $this->sanctionMeasureSeeder->seedForCentre($centre);
        $centre->addAdmin($teachers['rafael.exposito']);
        $centre->addAdmin($teachers['carmen.diaz']);
        foreach ($teachers as $t) {
            $year->addTeacher($t);
        }

        // ── ESO ───────────────────────────────────────────────────────────────
        $esoP = $this->makeProgramme($manager, 'ESO', $year);
        [$py1, $py2, $py3, $py4] = $this->makeEsoProgrammeYears($manager, $esoP);

        // ── Bachillerato ──────────────────────────────────────────────────────
        $bach = $this->makeProgramme($manager, 'Bachillerato', $year);
        $py1b = (new ProgrammeYear())->setName('1º Bachillerato')->setProgramme($bach);
        $py2b = (new ProgrammeYear())->setName('2º Bachillerato')->setProgramme($bach);
        $manager->persist($py1b);
        $manager->persist($py2b);

        // ── CFGS Desarrollo de Aplicaciones Web ───────────────────────────────
        $daw  = $this->makeProgramme($manager, 'CFGS Desarrollo de Aplicaciones Web', $year);
        $py1d = (new ProgrammeYear())->setName('1º DAW')->setProgramme($daw);
        $py2d = (new ProgrammeYear())->setName('2º DAW')->setProgramme($daw);
        $manager->persist($py1d);
        $manager->persist($py2d);

        return [
            [$py1, $py2, $py3, $py4],
            [$py1b, $py2b],
            [$py1d, $py2d],
        ];
    }

    /**
     * @param array<string, Teacher> $teachers
     * @return array{ProgrammeYear[], ProgrammeYear[]}
     */
    private function buildMonterrubio(ObjectManager $manager, array $teachers): array
    {
        $centre = (new EducationalCentre())
            ->setCode('41017845')
            ->setName('IES Monterrubio')
            ->setCity('Utrera');

        $year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $manager->persist($centre);
        $manager->persist($year);

        $this->behaviorSeeder->seedForCentre($centre);
        $this->sanctionMeasureSeeder->seedForCentre($centre);
        $centre->addAdmin($teachers['mariajose.alvarez']);
        $centre->addAdmin($teachers['pedro.fernandez']);
        foreach ($teachers as $t) {
            $year->addTeacher($t);
        }

        // ── ESO ───────────────────────────────────────────────────────────────
        $esoP = $this->makeProgramme($manager, 'ESO', $year);
        [$py1, $py2, $py3, $py4] = $this->makeEsoProgrammeYears($manager, $esoP);

        // ── Bachillerato ──────────────────────────────────────────────────────
        $bach = $this->makeProgramme($manager, 'Bachillerato', $year);
        $py1b = (new ProgrammeYear())->setName('1º Bachillerato')->setProgramme($bach);
        $py2b = (new ProgrammeYear())->setName('2º Bachillerato')->setProgramme($bach);
        $manager->persist($py1b);
        $manager->persist($py2b);

        return [
            [$py1, $py2, $py3, $py4],
            [$py1b, $py2b],
        ];
    }

    private function makeProgramme(ObjectManager $manager, string $name, AcademicYear $year): Programme
    {
        $p = (new Programme())->setName($name)->setAcademicYear($year);
        $manager->persist($p);
        return $p;
    }

    /** @return array{ProgrammeYear, ProgrammeYear, ProgrammeYear, ProgrammeYear} */
    private function makeEsoProgrammeYears(ObjectManager $manager, Programme $programme): array
    {
        $years = [];
        foreach (['1º ESO', '2º ESO', '3º ESO', '4º ESO'] as $name) {
            $py = (new ProgrammeYear())->setName($name)->setProgramme($programme);
            $manager->persist($py);
            $years[] = $py;
        }
        /** @var array{ProgrammeYear, ProgrammeYear, ProgrammeYear, ProgrammeYear} $years */
        return $years;
    }

    // ── Grupos y alumnado ─────────────────────────────────────────────────────

    /**
     * Crea un grupo por ProgrammeYear con el sufijo indicado y lo puebla de alumnado.
     *
     * @param ProgrammeYear[]        $pyears
     * @param array<string, Teacher> $teachers
     * @return Group[]
     */
    private function buildGroups(
        ObjectManager $manager,
        array $pyears,
        array $teachers,
        string $prefix,
        int $studentsPerGroup,
        string $suffix = '',
    ): array {
        $teacherList  = array_values($teachers);
        $tutorOffset  = 7;  // los primeros 7 son directivos/jefes/coordinadores
        $tutorCount   = max(1, count($teacherList) - $tutorOffset);
        $groups       = [];
        $tutorIdx     = 0;

        foreach ($pyears as $i => $py) {
            $abbr  = $py->getName();
            $abbr  = preg_replace('/[^A-ZÀ-Ÿa-zà-ÿ0-9º]/u', '', $abbr) ?? $abbr;
            $group = (new Group())
                ->setName($abbr . $suffix)
                ->setProgrammeYear($py);

            $tutor = $teacherList[$tutorOffset + ($tutorIdx % $tutorCount)];
            $tutorIdx++;
            $co    = $teacherList[$tutorOffset + ($tutorIdx % $tutorCount)];
            $tutorIdx++;

            $group->addTutor($tutor);
            $group->addTeacher($co);
            $manager->persist($group);

            for ($s = 1; $s <= $studentsPerGroup; $s++) {
                $student = new Student(new PersonName(
                    $this->firstName($prefix, $i, $s),
                    $this->lastName($prefix, $i, $s),
                ));
                $student->setStudentId(sprintf('%s%03d%02d', strtoupper($prefix), $i + 1, $s));
                $student->addGroup($group);
                $manager->persist($student);
            }

            $groups[] = $group;
        }

        return $groups;
    }

    private function firstName(string $prefix, int $groupIdx, int $studentIdx): string
    {
        $names = ['Laura', 'Carlos', 'María', 'David', 'Lucía', 'Alejandro', 'Ana', 'Jorge',
                  'Sofía', 'Miguel', 'Paula', 'Adrián', 'Sara', 'Diego', 'Marta', 'Pablo',
                  'Carla', 'Álvaro', 'Elena', 'Sergio', 'Irene', 'Rubén', 'Alba', 'Víctor',
                  'Claudia', 'Óscar', 'Natalia', 'Raúl', 'Valeria', 'Iván'];
        return $names[($groupIdx * 12 + $studentIdx) % count($names)];
    }

    private function lastName(string $prefix, int $groupIdx, int $studentIdx): string
    {
        $surnames = ['García', 'Martínez', 'López', 'Sánchez', 'González', 'Pérez', 'Rodríguez',
                     'Fernández', 'Jiménez', 'Moreno', 'Muñoz', 'Álvarez', 'Romero', 'Díaz',
                     'Herrera', 'Torres', 'Ruiz', 'Navarro', 'Molina', 'Blanco', 'Vega', 'Reyes'];
        $n  = count($surnames);
        $i1 = ($groupIdx * 7 + $studentIdx * 3) % $n;
        $i2 = ($groupIdx * 11 + $studentIdx * 5 + 7) % $n;
        if ($i1 === $i2) {
            $i2 = ($i2 + 1) % $n;
        }
        return $surnames[$i1] . ' ' . $surnames[$i2];
    }
}
