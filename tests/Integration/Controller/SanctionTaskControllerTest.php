<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\SanctionTask;
use App\Entity\SanctionTaskAttachment;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\SanctionTaskGenerator;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SanctionTaskControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('APP_LOG=false');
        $_ENV['APP_LOG']    = 'false';
        $_SERVER['APP_LOG'] = 'false';
    }

    // ── permisos ─────────────────────────────────────────────────────────────

    public function testAssignedTeacherCanOpenEditForm(): void
    {
        $world = $this->makeWorld('assigned');
        $task  = $this->makeTask($world, requiresDates: true);
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->editUrl($task->getSanction(), $task));

        self::assertResponseIsSuccessful();
    }

    public function testUnrelatedTeacherCannotOpenEditForm(): void
    {
        $world     = $this->makeWorld('unrelated');
        $task      = $this->makeTask($world, requiresDates: true);
        $unrelated = $this->makeTeacher('unrelated-viewer');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->editUrl($task->getSanction(), $task));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupTutorCannotOpenEditFormOfSomeoneElsesTask(): void
    {
        $world = $this->makeWorld('tutor');
        $task  = $this->makeTask($world, requiresDates: true);
        $tutor = $this->makeTeacher('tutor-noaccess');
        $world['group']->addTutor($tutor);
        $this->persist($world['group']);
        $this->loginAs($tutor, $world['centre']);

        $this->client->request('GET', $this->editUrl($task->getSanction(), $task));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanOpenEditFormOfSomeoneElsesTask(): void
    {
        $world = $this->makeWorld('admin');
        $task  = $this->makeTask($world, requiresDates: true);
        $admin = $this->makeTeacher('admin-access');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', $this->editUrl($task->getSanction(), $task));

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanCompleteSomeoneElsesTask(): void
    {
        $world  = $this->makeWorld('admin-post');
        $task   = $this->makeTask($world, requiresDates: true);
        $taskId = $task->getId()->toRfc4122();
        $admin  = $this->makeTeacher('admin-post-access');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '<p>Completado por un administrador.</p>',
        ]);

        self::assertResponseRedirects('/tareas-de-sancion');

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($taskId);
        self::assertNotNull($reloaded->getCompletedAt());
        self::assertSame('<p>Completado por un administrador.</p>', $reloaded->getDescription());
    }

    public function testUnrelatedTeacherPostReturns403(): void
    {
        $world     = $this->makeWorld('unrelatedpost');
        $task      = $this->makeTask($world, requiresDates: true);
        $unrelated = $this->makeTeacher('unrelated-post');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => 'whatever',
            'description' => '<p>Intento no autorizado.</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function testPostWithInvalidCsrfTokenReturns403(): void
    {
        $world = $this->makeWorld('csrf');
        $task  = $this->makeTask($world, requiresDates: true);
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => 'token-invalido',
            'description' => '<p>Trabajo asignado.</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── bloqueo en curso histórico ───────────────────────────────────────────

    public function testEditReturns403WhenViewingPastYear(): void
    {
        $world    = $this->makeWorld('pastyear');
        $task     = $this->makeTask($world, requiresDates: true);
        $pastYear = (new AcademicYear())->setName('2023-2024')->setEducationalCentre($world['centre']);
        $this->persist($pastYear);
        $this->loginAs($world['teacher'], $world['centre']);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', $this->editUrl($task->getSanction(), $task));

        self::assertResponseStatusCodeSame(403);
    }

    // ── flujo de cumplimentación ─────────────────────────────────────────────

    public function testSavingDescriptionCompletesTheTaskAndLogsSanctionTaskCompleted(): void
    {
        $world   = $this->makeWorld('complete');
        $task    = $this->makeTask($world, requiresDates: true);
        $taskId  = $task->getId()->toRfc4122();
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '<p>Ejercicios del tema 3.</p>',
        ]);

        self::assertResponseRedirects('/tareas-de-sancion');

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($taskId);
        self::assertNotNull($reloaded->getCompletedAt());
        self::assertSame('<p>Ejercicios del tema 3.</p>', $reloaded->getDescription());

        $logs = $this->em->getRepository(\App\Entity\ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_task.completed', $logs[0]->getActionType());
        self::assertSame($taskId, $logs[0]->getData()['entityId'] ?? null);
    }

    public function testEditingAnAlreadyCompletedTaskLogsSanctionTaskUpdated(): void
    {
        $world  = $this->makeWorld('update');
        $task   = $this->makeTask($world, requiresDates: true);
        $taskId = $task->getId()->toRfc4122();
        $task->setDescription('<p>Original.</p>')->setCompletedAt(new \DateTimeImmutable('-1 day'));
        $this->flush();
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '<p>Actualizado.</p>',
        ]);

        self::assertResponseRedirects('/tareas-de-sancion');

        $this->em->clear();
        $logs = $this->em->getRepository(\App\Entity\ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_task.updated', $logs[0]->getActionType());
        self::assertSame($taskId, $logs[0]->getData()['entityId'] ?? null);
    }

    public function testMarkingNotApplicableClearsDescriptionAndCompletesTask(): void
    {
        $world  = $this->makeWorld('notapplicable');
        $task   = $this->makeTask($world, requiresDates: true);
        $taskId = $task->getId()->toRfc4122();
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'         => $token,
            'not_applicable' => '1',
            'description'    => '',
        ]);

        self::assertResponseRedirects('/tareas-de-sancion');

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($taskId);
        self::assertTrue($reloaded->isNotApplicable());
        self::assertNull($reloaded->getDescription());
        self::assertNotNull($reloaded->getCompletedAt());
    }

    public function testEmptyDescriptionWithoutNotApplicableShowsError(): void
    {
        $world = $this->makeWorld('empty');
        $task  = $this->makeTask($world, requiresDates: true);
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '',
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($task->getId()->toRfc4122());
        self::assertNull($reloaded->getCompletedAt());
    }

    // ── adjuntos ─────────────────────────────────────────────────────────────

    public function testUploadingAValidAttachmentAttachesItToTheTask(): void
    {
        $world  = $this->makeWorld('upload');
        $task   = $this->makeTask($world, requiresDates: true);
        $taskId = $task->getId()->toRfc4122();
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'sti');
        file_put_contents($tmpFile, 'contenido de prueba');
        $upload = new UploadedFile($tmpFile, 'ejercicio.txt', 'text/plain', null, true);

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '<p>Trabajo con adjunto.</p>',
        ], ['attachments' => [$upload]]);

        self::assertResponseRedirects('/tareas-de-sancion');

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($taskId);
        self::assertCount(1, $reloaded->getAttachments());
        $attachment = $reloaded->getAttachments()->first();
        self::assertInstanceOf(SanctionTaskAttachment::class, $attachment);
        self::assertSame('ejercicio.txt', $attachment->getFilename());

        @unlink($tmpFile);
    }

    public function testUploadingAnOversizedAttachmentShowsError(): void
    {
        // upload_max_filesize/post_max_size son PHP_INI_PERDIR: no se pueden elevar en
        // tiempo de ejecución. Si el entorno los deja por debajo del límite de la propia
        // aplicación (10 MB), Symfony descarta el adjunto antes de llegar al controlador
        // (UPLOAD_ERR_INI_SIZE) y el escenario que queremos probar es irreproducible aquí.
        $realLimit = UploadedFile::getMaxFilesize();
        if ($realLimit <= 10 * 1024 * 1024) {
            self::markTestSkipped('upload_max_filesize/post_max_size del entorno son menores que el límite de la aplicación (10 MB).');
        }

        $world  = $this->makeWorld('oversized');
        $task   = $this->makeTask($world, requiresDates: true);
        $taskId = $task->getId()->toRfc4122();
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'stib');
        file_put_contents($tmpFile, str_repeat('a', (int) min($realLimit, 10 * 1024 * 1024 + 1024)));
        $upload = new UploadedFile($tmpFile, 'grande.txt', 'text/plain', null, true);

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '<p>Trabajo.</p>',
        ], ['attachments' => [$upload]]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($taskId);
        self::assertCount(0, $reloaded->getAttachments());

        @unlink($tmpFile);
    }

    public function testUploadingADisallowedMimeTypeShowsError(): void
    {
        $world  = $this->makeWorld('badmime');
        $task   = $this->makeTask($world, requiresDates: true);
        $taskId = $task->getId()->toRfc4122();
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($task->getSanction(), $task));
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'stih');
        file_put_contents($tmpFile, '<html><body>test</body></html>');
        $upload = new UploadedFile($tmpFile, 'pagina.html', 'text/html', null, true);

        $this->client->request('POST', $this->editUrl($task->getSanction(), $task), [
            '_token'      => $token,
            'description' => '<p>Trabajo.</p>',
        ], ['attachments' => [$upload]]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($taskId);
        self::assertCount(0, $reloaded->getAttachments());

        @unlink($tmpFile);
    }

    public function testAssignedTeacherCanDownloadOwnAttachment(): void
    {
        $world      = $this->makeWorld('download');
        $task       = $this->makeTask($world, requiresDates: true);
        $attachment = new SanctionTaskAttachment($task, 'notas.txt', 'text/plain', 4, 'test');
        $task->addAttachment($attachment);
        $this->persist($task, $attachment);
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->downloadUrl($task->getSanction(), $task, $attachment));

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
    }

    public function testTutorCanDownloadAttachmentBecauseTheyHaveSanctionViewAccess(): void
    {
        $world      = $this->makeWorld('tutordownload');
        $task       = $this->makeTask($world, requiresDates: true);
        $attachment = new SanctionTaskAttachment($task, 'notas.txt', 'text/plain', 4, 'test');
        $task->addAttachment($attachment);
        $this->persist($task, $attachment);

        $tutor = $this->makeTeacher('tutor-download');
        $world['group']->addTutor($tutor);
        $this->persist($world['group']);
        $this->loginAs($tutor, $world['centre']);

        $this->client->request('GET', $this->downloadUrl($task->getSanction(), $task, $attachment));

        self::assertResponseIsSuccessful();
    }

    public function testUnrelatedTeacherCannotDownloadAttachment(): void
    {
        $world      = $this->makeWorld('nodownload');
        $task       = $this->makeTask($world, requiresDates: true);
        $attachment = new SanctionTaskAttachment($task, 'notas.txt', 'text/plain', 4, 'test');
        $task->addAttachment($attachment);
        $this->persist($task, $attachment);

        $unrelated = $this->makeTeacher('unrelated-download');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->downloadUrl($task->getSanction(), $task, $attachment));

        self::assertResponseStatusCodeSame(403);
    }

    // ── contexto de solo lectura ─────────────────────────────────────────────

    public function testEditScreenShowsReadOnlySanctionContextWithoutExposingRestrictedData(): void
    {
        $world    = $this->makeWorld('context');
        $sanction = $this->makeSanction($world, requiresDates: true)
            ->setDetails('<p>Detalles reservados de la sanción.</p>')
            ->setCalendarLabel('Aula de convivencia');
        $this->flush();
        $task = new SanctionTask($sanction, $world['groupTeacher']);
        $this->persist($task);
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', $this->editUrl($sanction, $task));

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString($world['student']->getName()->getFirstName(), $html);
        self::assertStringContainsString('Aula de convivencia', $html);
        self::assertStringContainsString('Detalles reservados de la sanción.', $html);
        $effectiveFrom = $sanction->getEffectiveFrom();
        self::assertNotNull($effectiveFrom);
        self::assertStringContainsString($effectiveFrom->format('d/m/Y'), $html);
        // No contact info, observations, or notification history belong on this screen.
        self::assertStringNotContainsString($world['student']->getStudentId(), $html);
    }

    public function testEditScreenListsSiblingTasksWithoutLinkingToTheirContent(): void
    {
        $world        = $this->makeWorld('siblings');
        $otherTeacher = $this->makeTeacher('sibling-teacher');
        $world['group']->addTeacher($otherTeacher, 'Física');
        $this->persist($world['group']);

        $sanction = $this->makeSanction($world, requiresDates: true);
        /** @var SanctionTaskGenerator $generator */
        $generator = self::getContainer()->get(SanctionTaskGenerator::class);
        $tasks     = $generator->generateFor($sanction);
        self::assertCount(2, $tasks);

        $ownTask = null;
        foreach ($tasks as $t) {
            if ($t->getGroupTeacher()->getTeacher() === $world['teacher']) {
                $ownTask = $t;
            }
        }
        self::assertNotNull($ownTask);

        $this->loginAs($world['teacher'], $world['centre']);
        $crawler = $this->client->request('GET', $this->editUrl($sanction, $ownTask));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Física', $crawler->html());
        self::assertSelectorNotExists('a[href*="/tareas/"][href*="/editar"]:not([href*="' . $ownTask->getId()->toRfc4122() . '"])');
    }

    // ── índice ───────────────────────────────────────────────────────────────

    public function testIndexListsOwnPendingTasks(): void
    {
        $world = $this->makeWorld('index');
        $this->makeTask($world, requiresDates: true);
        $this->loginAs($world['teacher'], $world['centre']);

        $crawler = $this->client->request('GET', '/tareas-de-sancion');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Matemáticas', $crawler->html());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} */
    private function makeWorld(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'c'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
        $teacher = $this->makeTeacher($suffix);
        $group->addTeacher($teacher, 'Matemáticas');
        $groupTeacher = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $groupTeacher);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $teacher);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'groupTeacher');
    }

    private function makeTeacher(string $suffix): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername('teacher.' . $suffix . uniqid('', false))
            ->setEmail('teacher.' . $suffix . '@ejemplo.local');
        $this->persist($teacher);

        return $teacher;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeSanction(array $world, bool $requiresDates): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(!$requiresDates)
            ->setNoMeasureReason($requiresDates ? null : 'Sin medida')
            ->setEffectiveFrom($requiresDates ? new \DateTimeImmutable('+2 days') : null)
            ->setEffectiveTo($requiresDates ? new \DateTimeImmutable('+7 days') : null);

        if ($requiresDates) {
            $category = (new SanctionMeasureCategory())
                ->setEducationalCentre($world['centre'])
                ->setName('Correcciones')
                ->setPosition(0);
            $measure = (new SanctionMeasure())
                ->setEducationalCentre($world['centre'])
                ->setCategory($category)
                ->setName('Expulsión con actividades')
                ->setHasDateRange(true)
                ->setPosition(0)
                ->setActive(true);
            $this->persist($category, $measure);
            $sanction->addMeasure($measure);
        }

        $this->persist($sanction);

        return $sanction;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeTask(array $world, bool $requiresDates): SanctionTask
    {
        $sanction = $this->makeSanction($world, $requiresDates);

        $task = new SanctionTask($sanction, $world['groupTeacher']);
        $this->persist($task);

        return $task;
    }

    private function editUrl(Sanction $sanction, SanctionTask $task): string
    {
        return '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/' . $task->getId()->toRfc4122() . '/editar';
    }

    private function downloadUrl(Sanction $sanction, SanctionTask $task, SanctionTaskAttachment $attachment): string
    {
        return '/sanciones/' . $sanction->getId()->toRfc4122()
            . '/tareas/' . $task->getId()->toRfc4122()
            . '/adjuntos/' . $attachment->getId()->toRfc4122();
    }
}
