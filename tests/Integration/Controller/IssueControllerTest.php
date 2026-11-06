<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueChange;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\IssueManager;
use App\Service\IssuePrioritySeeder;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Incidencias: alta, ficha, observaciones, gestión por los responsables, adjuntos e historial. */
class IssueControllerTest extends ControllerTestCase
{
    private static int $seq = 0;

    // ── alta ──────────────────────────────────────────────────────────────────

    public function testATeacherCanReportAnIssueAndItStartsOpenWithoutPriority(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['rita'], $w['centre']);

        $this->client->request('GET', '/incidencias/nueva');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/incidencias/nueva', [
            '_token'      => $this->token('new_issue'),
            'type'        => $w['electricidad']->getId()->toRfc4122(),
            'description' => '<p>Se funde la luz del aula 12</p>',
        ]);

        $issue = $this->onlyIssue();
        self::assertResponseRedirects('/incidencias/' . $issue->getId()->toRfc4122());
        self::assertSame(IssueStatus::Open, $issue->getStatus());
        self::assertNull($issue->getPriority());
        self::assertSame(1, $issue->getNumber());
        self::assertSame('rita.reporter.' . self::$seq, $issue->getReporter()->getUsername());
        self::assertSame(['created'], array_map(static fn (IssueChange $c): string => $c->getField(), $issue->getChanges()->toArray()));
    }

    public function testTheDescriptionAndTheTypeAreMandatory(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['rita'], $w['centre']);

        $crawler = $this->client->request('POST', '/incidencias/nueva', [
            '_token'      => $this->token('new_issue'),
            'type'        => '',
            'description' => '<p>&nbsp;</p>',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Elige un tipo de incidencia.', $crawler->html());
        self::assertStringContainsString('Escribe la descripción.', $crawler->html());
        self::assertSame(0, $this->em->getRepository(Issue::class)->count([]));
    }

    public function testCreatingWithAnInvalidCsrfTokenIsDenied(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['rita'], $w['centre']);

        $this->client->request('POST', '/incidencias/nueva', ['_token' => 'x', 'type' => $w['electricidad']->getId()->toRfc4122(), 'description' => 'a']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnInactiveTypeCannotBeChosen(): void
    {
        $w = $this->makeWorld();
        $w['electricidad']->setActive(false);
        $this->flush();
        $this->loginAs($w['rita'], $w['centre']);

        $this->client->request('POST', '/incidencias/nueva', [
            '_token'      => $this->token('new_issue'),
            'type'        => $w['electricidad']->getId()->toRfc4122(),
            'description' => 'x',
        ]);

        self::assertSame(0, $this->em->getRepository(Issue::class)->count([]));
    }

    public function testAttachmentsAreStoredWithAThumbnailForImages(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['rita'], $w['centre']);

        $this->client->request('POST', '/incidencias/nueva', [
            '_token'      => $this->token('new_issue'),
            'type'        => $w['electricidad']->getId()->toRfc4122(),
            'description' => 'Con adjuntos',
        ], ['attachments' => [$this->pngUpload(), $this->textUpload()]]);

        $issue = $this->onlyIssue();
        self::assertCount(2, $issue->getAttachments());
        $byName = [];
        foreach ($issue->getAttachments() as $attachment) {
            $byName[$attachment->getFilename()] = $attachment;
        }
        self::assertTrue($byName['foto.png']->hasThumbnail());
        self::assertFalse($byName['notas.txt']->hasThumbnail());

        $id = $issue->getId()->toRfc4122();
        $this->client->request('GET', "/incidencias/$id/adjuntos/" . $byName['foto.png']->getId()->toRfc4122() . '?miniatura=1');
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testAFileThatIsNotAnAllowedTypeIsRejectedBeforeSavingAnything(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['rita'], $w['centre']);

        $path = tempnam(sys_get_temp_dir(), 'iss');
        file_put_contents($path, '<?php echo 1;');
        $bad = new UploadedFile($path, 'virus.php', 'application/x-php', null, true);

        $crawler = $this->client->request('POST', '/incidencias/nueva', [
            '_token'      => $this->token('new_issue'),
            'type'        => $w['electricidad']->getId()->toRfc4122(),
            'description' => 'x',
        ], ['attachments' => [$bad]]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tipo de fichero no permitido', $crawler->html());
        self::assertSame(0, $this->em->getRepository(Issue::class)->count([]));
    }

    // ── visibilidad ───────────────────────────────────────────────────────────

    public function testOnlyTheReporterTheResponsiblesAndManagementCanSeeAnIssue(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $url   = '/incidencias/' . $issue->getId()->toRfc4122();

        foreach (['rita' => 200, 'ana' => 200, 'luis' => 200, 'admin' => 200, 'otro' => 403] as $who => $status) {
            $this->loginAs($w[$who], $w['centre']);
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame($status, $who);
        }
    }

    public function testAnIssueOfAnotherCentreIsNotFound(): void
    {
        $w     = $this->makeWorld();
        $other = $this->makeWorld();
        $issue = $this->report($other);

        $this->loginAs($w['admin'], $w['centre']);
        $this->client->request('GET', '/incidencias/' . $issue->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    // ── observaciones y gestión ───────────────────────────────────────────────

    public function testAnyRelatedUserCanObserveButOnlyAResponsibleCanChangeTheStatusFromThere(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();

        // La persona que la registró observa, pero su intento de cerrarla se ignora.
        $this->loginAs($w['rita'], $w['centre']);
        $this->client->request('POST', "/incidencias/$id/observacion", ['_token' => $this->token("observe_issue_$id"), 'content' => 'Sigue sin luz', 'status' => 'solved']);
        $this->em->clear();
        $issue = $this->em->getRepository(Issue::class)->find($issue->getId());
        self::assertCount(1, $issue->getObservations());
        self::assertSame(IssueStatus::Open, $issue->getStatus());

        // La responsable la cierra desde la misma pantalla.
        $this->loginAs($w['ana'], $w['centre']);
        $this->client->request('POST', "/incidencias/$id/observacion", ['_token' => $this->token("observe_issue_$id"), 'content' => 'Cambiado el fluorescente', 'status' => 'solved']);
        $this->em->clear();
        $issue = $this->em->getRepository(Issue::class)->find($issue->getId());
        self::assertCount(2, $issue->getObservations());
        self::assertSame(IssueStatus::Solved, $issue->getStatus());
        self::assertNotNull($issue->getClosedAt());
    }

    public function testAnEmptyObservationIsRejected(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();

        $this->loginAs($w['rita'], $w['centre']);
        $this->client->request('POST', "/incidencias/$id/observacion", ['_token' => $this->token("observe_issue_$id"), 'content' => '   ']);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Issue::class)->find($issue->getId())->getObservations());
    }

    public function testAnIssueCannotGoInProgressWithoutAPriorityAndAssigningOneEnablesIt(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['ana'], $w['centre']);

        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $issue, ['status' => 'in_progress']));
        $this->em->clear();
        self::assertSame(IssueStatus::Open, $this->reload($issue)->getStatus());

        $high = $this->priority($w['centre'], 'Alta');
        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $issue, ['priority' => $high->getId()->toRfc4122(), 'status' => 'in_progress']));
        $this->em->clear();
        $reloaded = $this->reload($issue);
        self::assertSame(IssueStatus::InProgress, $reloaded->getStatus());
        self::assertSame('Alta', $reloaded->getPriority()?->getName());
        self::assertNotNull($reloaded->getMaxDue(), '«Alta» tiene un plazo máximo de 7 días lectivos');
    }

    public function testClosingAsDuplicateLinksTheOriginalAndAResponsibleCanReopen(): void
    {
        $w        = $this->makeWorld();
        $original = $this->report($w);
        $copy     = $this->report($w);
        $id       = $copy->getId()->toRfc4122();
        $this->loginAs($w['ana'], $w['centre']);

        // Sin indicar la original no se puede cerrar por duplicidad.
        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $copy, ['status' => 'duplicate']));
        $this->em->clear();
        self::assertSame(IssueStatus::Open, $this->reload($copy)->getStatus());

        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $copy, ['status' => 'duplicate', 'duplicate' => '#' . $original->getNumber()]));
        $this->em->clear();
        $closed = $this->reload($copy);
        self::assertSame(IssueStatus::Duplicate, $closed->getStatus());
        self::assertSame($original->getId()->toRfc4122(), $closed->getDuplicateOf()?->getId()->toRfc4122());

        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $copy, ['status' => 'open']));
        $this->em->clear();
        $reopened = $this->reload($copy);
        self::assertSame(IssueStatus::Open, $reopened->getStatus());
        self::assertNull($reopened->getClosedAt());
    }

    public function testTheReporterCannotManageTheIssue(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['rita'], $w['centre']);

        // No se le ofrece la gestión.
        $crawler = $this->client->request('GET', "/incidencias/$id");
        self::assertCount(0, $crawler->filter('form[action$="/gestionar"]'));
        self::assertCount(1, $crawler->filter('form[action$="/observacion"]'));
    }

    public function testEveryChangeIsRecordedInTheHistoryWithOldAndNewValues(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['ana'], $w['centre']);

        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $issue, ['priority' => $this->priority($w['centre'], 'Crítica')->getId()->toRfc4122()]));
        $this->client->request('POST', "/incidencias/$id/gestionar", $this->manageData($id, $issue, ['priority' => $this->priority($w['centre'], 'Crítica')->getId()->toRfc4122(), 'status' => 'not_applicable']));
        $this->em->clear();

        $changes = [];
        foreach ($this->reload($issue)->getChanges() as $change) {
            $changes[] = [$change->getField(), $change->getOldValue(), $change->getNewValue()];
        }
        self::assertContains([IssueManager::FIELD_PRIORITY, null, 'Crítica'], $changes);
        self::assertContains([IssueManager::FIELD_STATUS, 'open', 'not_applicable'], $changes);
    }

    public function testTheReporterCanEditTheDescriptionWhileOpenButNotAfterItIsClosed(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['rita'], $w['centre']);

        $this->client->request('POST', "/incidencias/$id/descripcion", ['_token' => $this->token("edit_issue_$id"), 'description' => '<p>Nueva descripción</p>']);
        $this->em->clear();
        self::assertStringContainsString('Nueva descripción', $this->reload($issue)->getDescription());

        $token    = $this->token("edit_issue_$id");
        $reloaded = $this->reload($issue);
        $reloaded->setStatus(IssueStatus::Solved);
        $this->flush();

        $this->client->request('POST', "/incidencias/$id/descripcion", ['_token' => $token, 'description' => '<p>Tarde</p>']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNothingCanBeChangedWhileViewingAPastYear(): void
    {
        $w     = $this->makeWorld();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['ana'], $w['centre']);
        $token    = $this->token("observe_issue_$id");
        $centre   = $this->em->getRepository(EducationalCentre::class)->find($w['centre']->getId());
        $pastYear = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $this->persist($pastYear);
        $this->flush();
        $this->viewPastYear($pastYear);

        $this->client->request('POST', "/incidencias/$id/observacion", ['_token' => $token, 'content' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function manageData(string $id, Issue $issue, array $overrides): array
    {
        return $overrides + [
            '_token'   => $this->token("manage_issue_$id"),
            'type'     => $issue->getType()->getId()->toRfc4122(),
            'priority' => $issue->getPriority()?->getId()->toRfc4122() ?? '',
            'status'   => $issue->getStatus()->value,
            'location' => '',
        ];
    }

    private function token(string $id): string
    {
        // Como en el navegador: el token se lee del formulario correspondiente.
        if ($id === 'new_issue') {
            return (string) $this->client->request('GET', '/incidencias/nueva')->filter('form[method="post"] [name="_token"]')->attr('value');
        }

        foreach (['observe_issue_' => 'observacion', 'edit_issue_' => 'descripcion', 'manage_issue_' => 'gestionar'] as $prefix => $action) {
            if (str_starts_with($id, $prefix)) {
                $crawler = $this->client->request('GET', '/incidencias/' . substr($id, strlen($prefix)));

                return (string) $crawler->filter('form[action$="/' . $action . '"] [name="_token"]')->attr('value');
            }
        }

        self::fail("Sin formulario para $id");
    }

    private function onlyIssue(): Issue
    {
        $this->em->clear();
        $all = $this->em->getRepository(Issue::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function reload(Issue $issue): Issue
    {
        $found = $this->em->getRepository(Issue::class)->find($issue->getId());
        self::assertNotNull($found);

        return $found;
    }

    private function priority(EducationalCentre $centre, string $name): IssuePriority
    {
        $found = $this->em->getRepository(IssuePriority::class)->findOneBy(['educationalCentre' => $centre, 'name' => $name]);
        self::assertNotNull($found);

        return $found;
    }

    /**
     * @param array<string, mixed> $w
     */
    private function report(array $w): Issue
    {
        return self::getContainer()->get(IssueManager::class)->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        $img  = imagecreatetruecolor(40, 30);
        imagepng($img, $path);

        return new UploadedFile($path, 'foto.png', 'image/png', null, true);
    }

    private function textUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'hola');

        return new UploadedFile($path, 'notas.txt', 'text/plain', null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Incidencias')))->setUsername("admin.iss.$seq")->setAdmin(true);
        $ana    = (new Teacher(new PersonName('Ana', 'Responsable')))->setUsername("ana.resp.c$seq");
        $luis   = (new Teacher(new PersonName('Luis', 'Raíz')))->setUsername("luis.raiz.$seq");
        $rita   = (new Teacher(new PersonName('Rita', 'Informante')))->setUsername("rita.reporter.$seq");
        $otro   = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername("otro.docente.$seq");
        $centre = (new EducationalCentre())->setCode("4500{$seq}77")->setName("IES Incidencias C$seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        foreach ([$ana, $luis, $rita, $otro] as $teacher) {
            $year->addTeacher($teacher);
        }
        $mantenimiento = (new IssueType())->setEducationalCentre($centre)->setName('Mantenimiento')->addManager($luis);
        $electricidad  = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->setParent($mantenimiento)->addManager($ana);

        $this->persist($admin, $ana, $luis, $rita, $otro, $centre, $year, $mantenimiento, $electricidad);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        $this->flush();

        return compact('admin', 'ana', 'luis', 'rita', 'otro', 'centre', 'year', 'mantenimiento', 'electricidad');
    }
}
