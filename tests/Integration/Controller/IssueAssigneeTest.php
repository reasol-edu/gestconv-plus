<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\EmailDigestItem;
use App\Entity\Issue;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\IssueManager;
use App\Service\IssuePrioritySeeder;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Incidencias asignadas a una persona: quién puede asignarlas y qué puede hacer quien las tiene. */
class IssueAssigneeTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private static int $seq = 0;

    public function testAResponsibleAssignsTheIssueToATeacherOfTheCourse(): void
    {
        $w     = $this->world();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['ana'], $w['centre']);

        $options = $this->client->request('GET', "/incidencias/$id")->filter('select#manage-assignee option')->each(static fn ($n): string => (string) $n->attr('value'));
        self::assertContains($w['marta']->getId()->toRfc4122(), $options);

        $this->post($w['ana'], $id, 'manage_issue_', 'gestionar', ['assignee' => $w['marta']->getId()->toRfc4122()] + $this->base($issue));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Issue::class)->find($issue->getId());
        self::assertSame($w['marta']->getId()->toRfc4122(), $reloaded?->getAssignee()?->getId()->toRfc4122());
        $fields = array_map(static fn ($c): string => $c->getField(), $reloaded->getChanges()->toArray());
        self::assertContains('assignee', $fields);

        // La persona asignada recibe el aviso de asignación.
        $recipients = array_map(static fn (EmailDigestItem $i): string => $i->getRecipient()->getName()->full() . ':' . $i->getEventKey(), $this->em->getRepository(EmailDigestItem::class)->findAll());
        self::assertContains('Marta Asignada:issue_assigned', $recipients);
    }

    public function testTheAssigneeSeesTheIssueObservesAndChangesPriorityAndStatusButNothingElse(): void
    {
        $w     = $this->world();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        self::getContainer()->get(IssueManager::class)->changeAssignee($issue, $w['ana'], $w['marta']);
        $this->loginAs($w['marta'], $w['centre']);

        // Ve la ficha, con el panel reducido (sin tipo, dependencia ni asignación) y sin poder eliminarla.
        $crawler = $this->client->request('GET', "/incidencias/$id");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select#manage-priority'));
        self::assertCount(0, $crawler->filter('select#manage-assignee'));
        self::assertCount(0, $crawler->filter('select#manage-type'));
        self::assertCount(0, $crawler->filter('form[action$="/eliminar"]'));

        // Observa.
        $this->post($w['marta'], $id, 'observe_issue_', 'observacion', ['content' => 'La reviso mañana']);
        // Cambia la prioridad y pasa a en proceso; intenta también cambiar el tipo, que se ignora.
        $this->post($w['marta'], $id, 'manage_issue_', 'gestionar', [
            'priority' => $this->priorityId($w, 'Alta'), 'status' => 'in_progress', 'type' => $w['limpieza']->getId()->toRfc4122(), 'assignee' => '',
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Issue::class)->find($issue->getId());
        self::assertCount(1, $reloaded?->getObservations());
        self::assertSame('Alta', $reloaded->getPriority()?->getName());
        self::assertSame(IssueStatus::InProgress, $reloaded->getStatus());
        self::assertSame($w['electricidad']->getId()->toRfc4122(), $reloaded->getType()->getId()->toRfc4122(), 'el tipo no se cambia');
        self::assertSame($w['marta']->getId()->toRfc4122(), $reloaded->getAssignee()?->getId()->toRfc4122(), 'tampoco se desasigna');
    }

    public function testSomeoneUnrelatedCannotSeeOrActOnTheIssue(): void
    {
        $w     = $this->world();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        $this->loginAs($w['otro'], $w['centre']);

        $this->client->request('GET', "/incidencias/$id");
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAssignedIssuesShowUpInTheListWithTheirOwnFilter(): void
    {
        $w     = $this->world();
        $issue = $this->report($w);
        $this->report($w);
        self::getContainer()->get(IssueManager::class)->changeAssignee($issue, $w['ana'], $w['marta']);
        $this->loginAs($w['marta'], $w['centre']);

        $count = function (string $scope) use ($w): int {
            $component = $this->createLiveComponent('IssueListComponent', ['centre' => $w['centre']], $this->client);
            $component->set('scope', $scope);

            return $component->render()->crawler()->filter('tbody tr')->count();
        };

        self::assertSame(1, $count(''), 'solo ve la que tiene asignada');
        self::assertSame(1, $count('assigned'));
        self::assertSame(0, $count('mine'));
    }

    public function testUnassigningClearsItAndTheNewAssigneeMustBeAnEligiblePerson(): void
    {
        $w     = $this->world();
        $issue = $this->report($w);
        $id    = $issue->getId()->toRfc4122();
        self::getContainer()->get(IssueManager::class)->changeAssignee($issue, $w['ana'], $w['marta']);
        $this->loginAs($w['ana'], $w['centre']);

        // Una persona que no es del curso no se acepta (se conserva la actual).
        $stranger = (new Teacher(new PersonName('Fuera', 'Del Curso')))->setUsername('fuera.curso.' . ++self::$seq);
        $this->persist($stranger);
        $this->post($w['ana'], $id, 'manage_issue_', 'gestionar', ['assignee' => $stranger->getId()->toRfc4122()] + $this->base($issue));
        $this->em->clear();
        self::assertSame($w['marta']->getId()->toRfc4122(), $this->em->getRepository(Issue::class)->find($issue->getId())?->getAssignee()?->getId()->toRfc4122());

        $this->post($w['ana'], $id, 'manage_issue_', 'gestionar', ['assignee' => ''] + $this->base($issue));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Issue::class)->find($issue->getId())?->getAssignee());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $data
     */
    private function post(Teacher $who, string $id, string $tokenPrefix, string $action, array $data): void
    {
        $crawler = $this->client->request('GET', "/incidencias/$id");
        $token   = (string) $crawler->filter('form[action$="/' . $action . '"] [name="_token"]')->attr('value');
        $this->client->request('POST', "/incidencias/$id/$action", ['_token' => $token] + $data);
        unset($who, $tokenPrefix);
    }

    /** @return array<string, string> lo que enviaría el panel sin tocar nada más */
    private function base(Issue $issue): array
    {
        return ['type' => $issue->getType()->getId()->toRfc4122(), 'priority' => '', 'status' => $issue->getStatus()->value, 'location' => ''];
    }

    /** @param array<string, mixed> $w */
    private function priorityId(array $w, string $name): string
    {
        $found = $this->em->getRepository(\App\Entity\IssuePriority::class)->findOneBy(['educationalCentre' => $w['centre'], 'name' => $name]);
        self::assertNotNull($found);

        return $found->getId()->toRfc4122();
    }

    /** @param array<string, mixed> $w */
    private function report(array $w): Issue
    {
        return self::getContainer()->get(IssueManager::class)->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, '<p>Sin luz</p>', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Asignar')))->setUsername("admin.asg.$seq")->setAdmin(true);
        $ana    = (new Teacher(new PersonName('Ana', 'Responsable')))->setUsername("ana.asg.$seq")->setEmail("ana$seq@example.org");
        $marta  = (new Teacher(new PersonName('Marta', 'Asignada')))->setUsername("marta.asg.$seq")->setEmail("marta$seq@example.org");
        $rita   = (new Teacher(new PersonName('Rita', 'Informante')))->setUsername("rita.asg.$seq");
        $otro   = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername("otro.asg.$seq");
        $centre = (new EducationalCentre())->setCode("4900{$seq}33")->setName("IES Asignar $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        foreach ([$ana, $marta, $rita, $otro] as $teacher) {
            $year->addTeacher($teacher);
        }
        $electricidad = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->addManager($ana);
        $limpieza     = (new IssueType())->setEducationalCentre($centre)->setName('Limpieza');

        $this->persist($admin, $ana, $marta, $rita, $otro, $centre, $year, $electricidad, $limpieza);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        $this->flush();

        return compact('admin', 'ana', 'marta', 'rita', 'otro', 'centre', 'year', 'electricidad', 'limpieza');
    }
}
