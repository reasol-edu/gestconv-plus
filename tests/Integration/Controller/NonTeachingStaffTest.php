<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use App\Tests\Integration\ControllerTestCase;

/** Personal no docente: solo accede a Incidencias, no aparece entre los docentes y se gestiona desde el centro. */
class NonTeachingStaffTest extends ControllerTestCase
{
    private static int $seq = 0;

    public function testItOnlyReachesTheIssuesModule(): void
    {
        $w = $this->world();
        $this->loginAs($w['paco'], $w['centre']);

        // Su inicio es el listado de incidencias.
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/incidencias');

        $this->client->request('GET', '/incidencias');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/incidencias/nueva');
        self::assertResponseIsSuccessful();

        // Ningún otro módulo.
        foreach (['/partes', '/partes/nuevo', '/partes/creados', '/partes/buscar-alumnos'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheyRegisterAnIssueWithAnAttachmentAndOpenIt(): void
    {
        $w = $this->world();
        $this->loginAs($w['paco'], $w['centre']);

        $crawler = $this->client->request('GET', '/incidencias/nueva');
        $token   = (string) $crawler->filter('form [name="_token"]')->attr('value');
        $path    = tempnam(sys_get_temp_dir(), 'nd') . '.txt';
        file_put_contents($path, 'adjunto de prueba');
        $this->client->request(
            'POST',
            '/incidencias/nueva',
            ['_token' => $token, 'type' => $w['type']->getId()->toRfc4122(), 'location' => '', 'description' => '<p>Persiana rota</p>'],
            ['attachments' => [new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'nota.txt', 'text/plain', null, true)]],
        );
        self::assertResponseRedirects();

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a[href*="/adjuntos/"]')->first();
        self::assertCount(1, $link, 'la ficha lista el adjunto');
        $this->client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testItCannotEnterTheCentreWhenTheModuleIsOffAndTheReasonIsExplained(): void
    {
        $w = $this->world();
        $this->switchOffTheModule();
        $this->loginAs($w['paco'], $w['centre']);

        $this->client->request('GET', '/incidencias');
        self::assertResponseRedirects('/seleccion/centro');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('el módulo de Incidencias está desactivado', $crawler->text());
        self::assertStringContainsString($w['centre']->getName(), $crawler->text());

        // El centro bloqueado no ofrece formulario para elegirlo.
        self::assertCount(0, $crawler->filter('form[action$="/seleccion/centro/' . $w['centre']->getId()->toRfc4122() . '"]'));
    }

    public function testTheyAreHiddenFromTeacherListsAndTheyBelongToTheCentreWithoutAYear(): void
    {
        $w = $this->world();
        $this->em->clear();
        $repository = self::getContainer()->get(TeacherRepository::class);

        $usernames = array_map(static fn (Teacher $t): string => $t->getUsername(), $repository->findAllOrderedByName());
        self::assertContains($w['ana']->getUsername(), $usernames);
        self::assertNotContains($w['paco']->getUsername(), $usernames);

        $found = $repository->findNonTeachingByCentre($this->em->getRepository(EducationalCentre::class)->find($w['centre']->getId()));
        self::assertSame([$w['paco']->getUsername()], array_map(static fn (Teacher $t): string => $t->getUsername(), $found));
    }

    public function testTheCentreManagementAddsAnExistingAccountByEmailOrUsername(): void
    {
        $w = $this->world();
        $other = (new Teacher(new PersonName('Lola', 'Limpieza')))->setUsername('lola.nd.' . ++self::$seq)->setEmail('lola' . self::$seq . '@example.org')->setNonTeaching(true);
        $this->persist($other);
        $this->loginAs($w['ana'], $w['centre']);

        $this->add($w, strtoupper((string) $other->getEmail()));
        self::assertResponseRedirects();
        $this->em->clear();
        $centre = $this->em->getRepository(EducationalCentre::class)->find($w['centre']->getId());
        self::assertCount(2, $centre?->getNonTeachingStaff() ?? []);

        // Un docente no se puede incorporar como personal no docente.
        $this->add($w, $w['ana']->getUsername());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('es de un docente', $crawler->text());
        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(EducationalCentre::class)->find($w['centre']->getId())?->getNonTeachingStaff() ?? []);
    }

    public function testAnUnknownIdentifierLeadsToTheRegistrationForm(): void
    {
        $w = $this->world();
        $this->loginAs($w['ana'], $w['centre']);

        $this->add($w, 'nueva@example.org');
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSame('nueva@example.org', $crawler->filter('input[name="email"]')->attr('value'));
        self::assertSame('', $crawler->filter('input[name="username"]')->attr('value'));
    }

    public function testRegisteringWithAPasswordForcesItsChange(): void
    {
        $w = $this->world();
        $this->loginAs($w['ana'], $w['centre']);

        $this->register($w, ['first_name' => 'Nuria', 'last_name' => 'Conserje', 'username' => 'nuria.c', 'email' => 'nuria@example.org', 'mode' => 'password', 'password' => 'Clave-Inicial-123']);
        self::assertResponseRedirects();

        $this->em->clear();
        $account = self::getContainer()->get(TeacherRepository::class)->findByUsername('nuria.c');
        self::assertNotNull($account);
        self::assertTrue($account->isNonTeaching());
        self::assertFalse($account->isExternal());
        self::assertTrue($account->isForcePasswordChange());
        self::assertNotNull($account->getPassword());
        $centre = $this->em->getRepository(EducationalCentre::class)->find($w['centre']->getId());
        self::assertTrue($centre?->getNonTeachingStaff()->contains($account) ?? false);
    }

    public function testRegisteringWithAnInvitationStoresATokenAndSendsTheEmail(): void
    {
        $w = $this->world();
        $this->loginAs($w['ana'], $w['centre']);

        $this->register($w, ['first_name' => 'Ines', 'last_name' => 'Admin', 'username' => 'ines.a', 'email' => 'ines@example.org', 'mode' => 'invitation', 'password' => '']);
        self::assertResponseRedirects();

        $this->em->clear();
        $account = self::getContainer()->get(TeacherRepository::class)->findByUsername('ines.a');
        self::assertNotNull($account);
        self::assertNotNull($account->getPasswordResetToken());
        self::assertFalse($account->isPasswordResetTokenExpired());
        self::assertFalse($account->isForcePasswordChange(), 'establece su contraseña con el enlace, no hace falta cambiarla después');
        self::assertEmailCount(1);
        self::assertStringContainsString('ines@example.org', (string) self::getMailerMessage()?->getHeaders()->get('To')?->getBodyAsString());
    }

    public function testRegistrationValidatesRequiredFieldsAndDuplicates(): void
    {
        $w = $this->world();
        $this->loginAs($w['ana'], $w['centre']);

        $this->register($w, ['first_name' => '', 'last_name' => '', 'username' => $w['paco']->getUsername(), 'email' => '', 'mode' => 'password', 'password' => '']);
        self::assertResponseIsSuccessful();
        $text = $this->client->getCrawler()->text();
        self::assertStringContainsString('El nombre es obligatorio', $text);
        self::assertStringContainsString('Ya existe una cuenta con este identificador', $text);
        self::assertStringContainsString('El correo electrónico es obligatorio', $text);
        self::assertStringContainsString('La contraseña es obligatoria', $text);
    }

    public function testEditingAndRemovingKeepsTheirIssuesButDropsTheResponsibility(): void
    {
        $w = $this->world();
        $this->loginAs($w['ana'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();
        $pacoId   = $w['paco']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', "/centro/$centreId/personal-no-docente/$pacoId/editar");
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('[name="_token"]')->attr('value');
        $this->client->request('POST', "/centro/$centreId/personal-no-docente/$pacoId/editar", [
            '_token' => $token, 'first_name' => 'Francisco', 'last_name' => 'Mantenimiento', 'username' => $w['paco']->getUsername(),
            'email' => 'francisco@example.org', 'password' => '', 'active' => '1',
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('francisco@example.org', $this->em->getRepository(Teacher::class)->find($w['paco']->getId())?->getEmail());

        $crawler = $this->client->request('GET', "/centro/$centreId/personal-no-docente");
        $token   = (string) $crawler->filter('form[action$="/quitar"] [name="_token"]')->attr('value');
        $this->client->request('POST', "/centro/$centreId/personal-no-docente/$pacoId/quitar", ['_token' => $token]);
        self::assertResponseRedirects();

        $this->em->clear();
        $centre = $this->em->getRepository(EducationalCentre::class)->find($w['centre']->getId());
        self::assertCount(0, $centre?->getNonTeachingStaff() ?? []);
        $type = $this->em->getRepository(IssueType::class)->find($w['type']->getId());
        self::assertCount(0, $type?->getManagers() ?? []);
        self::assertNotNull($this->em->getRepository(Teacher::class)->find($w['paco']->getId()), 'la cuenta global no se borra');
    }

    public function testTheyCanBeResponsibleOfAnIssueTypeAndOnlyTheCentreSeesThem(): void
    {
        $w = $this->world();
        $this->loginAs($w['ana'], $w['centre']);
        $centreId = $w['centre']->getId()->toRfc4122();

        $crawler = $this->client->request('GET', "/centro/$centreId/incidencias");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($w['paco']->getName()->getLastName(), $crawler->html());
    }

    public function testTheTeacherImportNeverPicksUpANonTeachingAccount(): void
    {
        $w = $this->world();

        $plan = self::getContainer()->get(\App\Service\CentreTeacherImporter::class)->plan($w['year'], [
            ['username' => $w['paco']->getUsername(), 'firstName' => 'Paco', 'lastName' => 'Conserje', 'email' => null],
            ['username' => 'docente.nuevo.' . $w['year']->getId()->toRfc4122(), 'firstName' => 'Nueva', 'lastName' => 'Docente', 'email' => null],
        ]);

        self::assertCount(1, $plan);
        self::assertSame('new', $plan[0]->status);
        self::assertFalse($w['year']->getTeachers()->contains($w['paco']));
    }

    public function testAResponsibleWithoutACourseGetsAccessAndNoticesForTheirType(): void
    {
        $w = $this->world();
        $rita = (new Teacher(new PersonName('Rita', 'Informante')))->setUsername('rita.nd.' . self::$seq);
        $w['year']->addTeacher($rita);
        $this->persist($rita);
        $issue = self::getContainer()->get(\App\Service\IssueManager::class)->create($w['centre'], $w['year'], $rita, $w['type'], null, null, '<p>Gotera</p>', []);
        $id    = $issue->getId()->toRfc4122();

        // Se le avisa como responsable del tipo.
        $recipients = array_map(static fn (\App\Entity\EmailDigestItem $i): string => $i->getRecipient()->getUsername(), $this->em->getRepository(\App\Entity\EmailDigestItem::class)->findAll());
        self::assertContains($w['paco']->getUsername(), $recipients);

        // La ve, la gestiona (prioridad) y descarga su informe, sin pertenecer a ningún curso.
        self::getContainer()->get(\App\Service\IssuePrioritySeeder::class)->seedForCentre($w['centre']);
        $this->flush();
        $this->loginAs($w['paco'], $w['centre']);
        $crawler = $this->client->request('GET', "/incidencias/$id");
        self::assertResponseIsSuccessful();
        $priority = $this->em->getRepository(\App\Entity\IssuePriority::class)->findOneBy(['educationalCentre' => $w['centre'], 'name' => 'Alta']);
        self::assertNotNull($priority);
        $token = (string) $crawler->filter('form[action$="/gestionar"] [name="_token"]')->attr('value');
        $this->client->request('POST', "/incidencias/$id/gestionar", [
            '_token' => $token, 'type' => $w['type']->getId()->toRfc4122(), 'priority' => $priority->getId()->toRfc4122(), 'status' => 'open', 'location' => '',
        ]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('Alta', $this->em->getRepository(\App\Entity\Issue::class)->find($issue->getId())?->getPriority()?->getName());

        $this->client->request('GET', "/incidencias/$id/informe");
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testTheirPersonalSettingsOnlyOfferIssueNoticesAndEmailDelivery(): void
    {
        $w = $this->world();

        $this->loginAs($w['ana'], $w['centre']);
        $teacherHtml = $this->client->request('GET', '/perfil/ajustes')->html();
        self::assertStringContainsString('notifications.receive_report_created', $teacherHtml);

        $this->loginAs($w['paco'], $w['centre']);
        $html = $this->client->request('GET', '/perfil/ajustes')->html();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('notifications.receive_report_created', $html);
        self::assertStringNotContainsString('notifications.receive_sanction_notified', $html);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $w */
    private function add(array $w, string $identifier): void
    {
        $centreId = $w['centre']->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', "/centro/$centreId/personal-no-docente");
        $token    = (string) $crawler->filter('form[action$="/a%C3%B1adir"] [name="_token"], form[action$="/añadir"] [name="_token"]')->attr('value');
        $this->client->request('POST', "/centro/$centreId/personal-no-docente/añadir", ['_token' => $token, 'identifier' => $identifier]);
    }

    /**
     * @param array<string, mixed>  $w
     * @param array<string, string> $data
     */
    private function register(array $w, array $data): void
    {
        $centreId = $w['centre']->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', "/centro/$centreId/personal-no-docente/registrar");
        $token    = (string) $crawler->filter('[name="_token"]')->attr('value');
        $this->client->request('POST', "/centro/$centreId/personal-no-docente/registrar", ['_token' => $token] + $data);
    }

    private function switchOffTheModule(): void
    {
        $definition = (new SettingDefinition())->setKey('features.issues')->setType(SettingType::Boolean)->setCategory('settings.category.features')
            ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue('false'));
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $seq    = ++self::$seq;
        $ana    = (new Teacher(new PersonName('Ana', 'Dirección')))->setUsername("ana.nd.$seq")->setEmail("ana.nd$seq@example.org");
        $paco   = (new Teacher(new PersonName('Paco', 'Conserje')))->setUsername("paco.nd.$seq")->setEmail("paco.nd$seq@example.org")->setNonTeaching(true);
        $centre = (new EducationalCentre())->setCode("4800{$seq}44")->setName("IES No Docente $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $year->addTeacher($ana);
        $type = (new IssueType())->setEducationalCentre($centre)->setName('Mantenimiento')->addManager($paco);

        $this->persist($ana, $paco, $centre, $year, $type);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($ana);
        $centre->addNonTeachingStaff($paco);
        $this->flush();

        return compact('ana', 'paco', 'centre', 'year', 'type');
    }
}
