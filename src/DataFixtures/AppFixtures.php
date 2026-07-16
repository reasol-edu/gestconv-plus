<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\CentreProvisioner;
use App\Service\CsvReader;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Los datos de docentes y alumnado viven en CSVs versionados (data/) con el
 * mismo formato que exporta Séneca, de modo que también sirven para probar a
 * mano los flujos de importación. Aquí solo quedan la estructura (cursos,
 * grupos, tutorías) y los roles.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Connection $connection,
        private readonly CentreProvisioner $centreProvisioner,
        private readonly CsvReader $csvReader,
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

        $aTeachers = $this->loadTeachers($manager, 'docentes-ada-lovelace.csv', $ejemploHash, ['rafael.exposito']);
        $mTeachers = $this->loadTeachers($manager, 'docentes-monterrubio.csv', $ejemploHash, ['mariajose.alvarez']);
        $manager->flush();

        [$aEsoCourses, $aBachCourses, $aDawCourses] = $this->buildAdaLovelace($manager, $aTeachers);
        [$mEsoCourses, $mBachCourses]               = $this->buildMonterrubio($manager, $mTeachers);

        $aStudents = $this->studentsByUnit('alumnado-ada-lovelace.csv');
        $mStudents = $this->studentsByUnit('alumnado-monterrubio.csv');

        // IES Ada Lovelace: dos grupos por curso en ESO, uno en Bach. y uno en DAW.
        $this->buildGroups($manager, $aEsoCourses, $aTeachers, $aStudents, ' A');
        $this->buildGroups($manager, $aEsoCourses, $aTeachers, $aStudents, ' B');
        $this->buildGroups($manager, $aBachCourses, $aTeachers, $aStudents);
        $this->buildGroups($manager, $aDawCourses,  $aTeachers, $aStudents);

        // IES Monterrubio: dos grupos por curso en ESO, uno en Bach.
        $this->buildGroups($manager, $mEsoCourses, $mTeachers, $mStudents, ' A');
        $this->buildGroups($manager, $mEsoCourses, $mTeachers, $mStudents, ' B');
        $this->buildGroups($manager, $mBachCourses, $mTeachers, $mStudents);

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

            'DELETE FROM ' . $q('course'),
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

    // ── Lectura de CSVs ───────────────────────────────────────────────────────

    /** @return array{headers: list<string>, rows: list<array<string, string>>} */
    private function parseCsv(string $file): array
    {
        $path    = __DIR__ . '/data/' . $file;
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('No se pudo leer el CSV de fixtures "%s".', $path));
        }

        return $this->csvReader->parse($content);
    }

    // ── Creación de docentes ──────────────────────────────────────────────────

    /**
     * Crea los docentes a partir de un CSV con el formato de Séneca
     * ("Empleado/a" como "Apellidos, Nombre" y "Usuario IdEA"). El orden del
     * CSV importa: los 7 primeros son equipo directivo/coordinación y el resto
     * entra en el reparto rotatorio de tutorías de buildGroups().
     *
     * @param string[] $globalAdmins
     * @return array<string, Teacher>
     */
    private function loadTeachers(ObjectManager $manager, string $file, string $passwordHash, array $globalAdmins = []): array
    {
        $teachers = [];
        foreach ($this->parseCsv($file)['rows'] as $row) {
            $username = $row['Usuario IdEA'] ?? '';
            $parts    = explode(',', $row['Empleado/a'] ?? '', 2);
            $last     = trim($parts[0]);
            $first    = trim($parts[1] ?? '');

            $t = new Teacher(new PersonName($first, $last));
            $t->setUsername($username);
            $t->setPassword($passwordHash);
            if (in_array($username, $globalAdmins, true)) {
                $t->setAdmin(true);
            }
            $manager->persist($t);
            $teachers[$username] = $t;
        }

        return $teachers;
    }

    // ── Estructura académica ──────────────────────────────────────────────────

    /**
     * Devuelve [esoCourses, bachCourses, dawCourses].
     *
     * @param array<string, Teacher> $teachers
     * @return array{Course[], Course[], Course[]}
     */
    private function buildAdaLovelace(ObjectManager $manager, array $teachers): array
    {
        $centre = $this->centreProvisioner->provision('23006123', 'IES Ada Lovelace', 'Linares', '2025-2026');
        $year   = $centre->requireActiveAcademicYear();

        $centre->addAdmin($teachers['rafael.exposito']);
        $centre->addAdmin($teachers['carmen.diaz']);
        foreach ($teachers as $t) {
            $year->addTeacher($t);
        }

        $esoCourses  = $this->makeEsoCourses($manager, $year);
        $bachCourses = $this->makeCourses($manager, $year, ['1º Bachillerato', '2º Bachillerato']);
        $dawCourses  = $this->makeCourses($manager, $year, ['1º DAW', '2º DAW']);

        return [$esoCourses, $bachCourses, $dawCourses];
    }

    /**
     * @param array<string, Teacher> $teachers
     * @return array{Course[], Course[]}
     */
    private function buildMonterrubio(ObjectManager $manager, array $teachers): array
    {
        $centre = $this->centreProvisioner->provision('41017845', 'IES Monterrubio', 'Utrera', '2025-2026');
        $year   = $centre->requireActiveAcademicYear();

        $centre->addAdmin($teachers['mariajose.alvarez']);
        $centre->addAdmin($teachers['pedro.fernandez']);
        foreach ($teachers as $t) {
            $year->addTeacher($t);
        }

        $esoCourses  = $this->makeEsoCourses($manager, $year);
        $bachCourses = $this->makeCourses($manager, $year, ['1º Bachillerato', '2º Bachillerato']);

        return [$esoCourses, $bachCourses];
    }

    /** @return Course[] */
    private function makeEsoCourses(ObjectManager $manager, AcademicYear $year): array
    {
        return $this->makeCourses($manager, $year, ['1º ESO', '2º ESO', '3º ESO', '4º ESO']);
    }

    /**
     * @param string[] $names
     * @return Course[]
     */
    private function makeCourses(ObjectManager $manager, AcademicYear $year, array $names): array
    {
        $courses = [];
        foreach ($names as $name) {
            $course = (new Course())->setName($name)->setAcademicYear($year);
            $manager->persist($course);
            $courses[] = $course;
        }
        return $courses;
    }

    // ── Grupos y alumnado ─────────────────────────────────────────────────────

    /**
     * Agrupa las filas del CSV de alumnado por la columna "Unidad".
     *
     * @return array<string, list<array<string, string>>>
     */
    private function studentsByUnit(string $file): array
    {
        $byUnit = [];
        foreach ($this->parseCsv($file)['rows'] as $row) {
            $byUnit[$row['Unidad'] ?? ''][] = $row;
        }

        return $byUnit;
    }

    /**
     * Crea un grupo por Course con el sufijo indicado y lo puebla con el
     * alumnado del CSV cuya "Unidad" coincide con el nombre del grupo.
     *
     * @param Course[]                                 $courses
     * @param array<string, Teacher>                   $teachers
     * @param array<string, list<array<string, string>>> $studentsByUnit
     * @return Group[]
     */
    private function buildGroups(
        ObjectManager $manager,
        array $courses,
        array $teachers,
        array $studentsByUnit,
        string $suffix = '',
    ): array {
        $teacherList  = array_values($teachers);
        $tutorOffset  = 7;  // los primeros 7 son directivos/jefes/coordinadores
        $tutorCount   = max(1, count($teacherList) - $tutorOffset);
        $groups       = [];
        $tutorIdx     = 0;

        foreach ($courses as $course) {
            $abbr  = $course->getName();
            $abbr  = preg_replace('/[^A-ZÀ-Ÿa-zà-ÿ0-9º]/u', '', $abbr) ?? $abbr;
            $name  = $abbr . $suffix;
            $group = new Group();
            $group->setName($name);
            $group->setCourse($course);

            $tutor = $teacherList[$tutorOffset + ($tutorIdx % $tutorCount)];
            $tutorIdx++;
            $co    = $teacherList[$tutorOffset + ($tutorIdx % $tutorCount)];
            $tutorIdx++;

            $group->addTutor($tutor);
            $group->addTeacher($co, 'Cotutoría');
            $manager->persist($group);

            $rows = $studentsByUnit[$name]
                ?? throw new \RuntimeException(sprintf('El CSV de alumnado no tiene filas para la unidad "%s".', $name));

            foreach ($rows as $row) {
                $student = new Student(new PersonName(
                    $row['Nombre'] ?? '',
                    trim(($row['Primer apellido'] ?? '') . ' ' . ($row['Segundo apellido'] ?? '')),
                ));
                $student->setStudentId($row['Nº Id. Escolar'] ?? '');
                $student->addGroup($group);
                $manager->persist($student);
            }

            $groups[] = $group;
        }

        return $groups;
    }
}
