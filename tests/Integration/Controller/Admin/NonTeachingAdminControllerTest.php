<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** La administración de la plataforma gestiona el personal no docente, aparte de los docentes. */
class NonTeachingAdminControllerTest extends ControllerTestCase
{
    private static int $seq = 0;

    public function testTheUsersPageSeparatesNonTeachingStaffFromTeachers(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin']);

        $crawler = $this->client->request('GET', '/admin/usuarios');
        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Usuarios', $text);
        self::assertStringContainsString('Personal no docente', $text);

        // El personal no docente sale en su propio bloque, con sus centros; los docentes, en el suyo.
        $block = $crawler->filter('section')->eq(1);
        self::assertStringContainsString($w['paco']->getUsername(), $block->text());
        self::assertStringContainsString('IES Uno', $block->text());
        self::assertStringNotContainsString($w['doc']->getUsername(), $block->text());
        self::assertStringNotContainsString($w['paco']->getUsername(), $crawler->filter('section')->eq(0)->text());
    }

    public function testItIsOnlyForPlatformAdmins(): void
    {
        $w = $this->world();
        $this->loginAs($w['doc']);

        $this->client->request('GET', '/admin/usuarios/personal-no-docente/nuevo');
        self::assertResponseStatusCodeSame(403);
    }

    public function testItRegistersAnAccountWithAPasswordAndCentres(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin']);

        $crawler = $this->client->request('GET', '/admin/usuarios/personal-no-docente/nuevo');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('input[name="centres[]"]'));

        $this->client->request('POST', '/admin/usuarios/personal-no-docente/nuevo', [
            '_token' => (string) $crawler->filter('[name="_token"]')->attr('value'),
            'first_name' => 'Nuria', 'last_name' => 'Conserje', 'username' => 'nuria.adm', 'email' => 'nuria.adm@example.org',
            'mode' => 'password', 'password' => 'Clave-Inicial-123',
            'centres' => [$w['uno']->getId()->toRfc4122(), $w['dos']->getId()->toRfc4122()],
        ]);
        self::assertResponseRedirects('/admin/usuarios');

        $this->em->clear();
        $account = $this->em->getRepository(Teacher::class)->findOneBy(['username' => 'nuria.adm']);
        self::assertNotNull($account);
        self::assertTrue($account->isNonTeaching());
        self::assertTrue($account->isForcePasswordChange());
        foreach (['uno', 'dos'] as $key) {
            $centre = $this->em->getRepository(EducationalCentre::class)->find($w[$key]->getId());
            self::assertTrue($centre?->getNonTeachingStaff()->contains($account) ?? false, $key);
        }
    }

    public function testItInvitesWithoutACentreAndValidatesTheData(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin']);

        $crawler = $this->client->request('GET', '/admin/usuarios/personal-no-docente/nuevo');
        $token   = (string) $crawler->filter('[name="_token"]')->attr('value');

        // Datos inválidos y duplicados.
        $this->client->request('POST', '/admin/usuarios/personal-no-docente/nuevo', [
            '_token' => $token, 'first_name' => '', 'last_name' => '', 'username' => $w['paco']->getUsername(), 'email' => '', 'mode' => 'password', 'password' => '',
        ]);
        self::assertResponseIsSuccessful();
        $text = $this->client->getCrawler()->text();
        self::assertStringContainsString('El nombre es obligatorio', $text);
        self::assertStringContainsString('Ya existe una cuenta con este identificador', $text);
        self::assertStringContainsString('El correo electrónico es obligatorio', $text);

        // Invitación, sin centro.
        $this->client->request('POST', '/admin/usuarios/personal-no-docente/nuevo', [
            '_token' => $token, 'first_name' => 'Ines', 'last_name' => 'Admin', 'username' => 'ines.adm', 'email' => 'ines.adm@example.org', 'mode' => 'invitation', 'password' => '',
        ]);
        self::assertResponseRedirects('/admin/usuarios');
        $this->em->clear();
        $account = $this->em->getRepository(Teacher::class)->findOneBy(['username' => 'ines.adm']);
        self::assertNotNull($account?->getPasswordResetToken());
        self::assertEmailCount(1);
    }

    public function testEditingChangesTheCentresAndRemovingOneDropsTheResponsibility(): void
    {
        $w  = $this->world();
        $id = $w['paco']->getId()->toRfc4122();
        $this->loginAs($w['admin']);

        $crawler = $this->client->request('GET', "/admin/usuarios/personal-no-docente/$id/editar");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="centres[]"][checked]'));

        // Pasa del centro Uno al centro Dos y cambia su correo.
        $this->client->request('POST', "/admin/usuarios/personal-no-docente/$id/editar", [
            '_token' => (string) $crawler->filter('[name="_token"]')->attr('value'),
            'first_name' => 'Paco', 'last_name' => 'Conserje', 'username' => $w['paco']->getUsername(), 'email' => 'nuevo.paco@example.org', 'password' => '', 'active' => '1',
            'centres' => [$w['dos']->getId()->toRfc4122()],
        ]);
        self::assertResponseRedirects('/admin/usuarios');

        $this->em->clear();
        self::assertSame('nuevo.paco@example.org', $this->em->getRepository(Teacher::class)->find($w['paco']->getId())?->getEmail());
        $uno = $this->em->getRepository(EducationalCentre::class)->find($w['uno']->getId());
        $dos = $this->em->getRepository(EducationalCentre::class)->find($w['dos']->getId());
        self::assertCount(0, $uno?->getNonTeachingStaff() ?? []);
        self::assertCount(1, $dos?->getNonTeachingStaff() ?? []);
        self::assertCount(0, $this->em->getRepository(IssueType::class)->find($w['type']->getId())?->getManagers() ?? [], 'ya no es responsable del tipo del centro Uno');
    }

    public function testATeacherEditLinkForANonTeachingAccountGoesToItsOwnForm(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin']);

        $this->client->request('GET', '/admin/usuarios/' . $w['paco']->getId()->toRfc4122());
        self::assertResponseRedirects('/admin/usuarios/personal-no-docente/' . $w['paco']->getId()->toRfc4122() . '/editar');
    }

    public function testItReinvitesAndDeletesAnAccount(): void
    {
        $w  = $this->world();
        $id = $w['paco']->getId()->toRfc4122();
        $this->loginAs($w['admin']);

        $crawler = $this->client->request('GET', '/admin/usuarios');
        $token   = (string) $crawler->filter('form[action$="/' . $id . '/invitar"] [name="_token"]')->attr('value');
        $this->client->request('POST', "/admin/usuarios/personal-no-docente/$id/invitar", ['_token' => $token]);
        self::assertResponseRedirects('/admin/usuarios');
        self::assertEmailCount(1);

        $crawler = $this->client->request('GET', '/admin/usuarios');
        $token   = (string) $crawler->filter('form[action$="/' . $id . '/eliminar"] [name="_token"]')->attr('value');
        $this->client->request('POST', "/admin/usuarios/personal-no-docente/$id/eliminar", ['_token' => $token]);
        self::assertResponseRedirects('/admin/usuarios');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Teacher::class)->find($w['paco']->getId()));
    }

    public function testTheAdminCanImpersonateANonTeachingAccountFromTheList(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin']);

        $crawler = $this->client->request('GET', '/admin/usuarios');
        $link    = $crawler->filter('a[href*="_switch_user=' . $w['paco']->getUsername() . '"]');
        self::assertCount(1, $link, 'hay un botón «Acceder como»');
        self::assertCount(1, $crawler->filter('section:nth-of-type(2) form[action$="/eliminar"] button svg'), 'con icono, igual que el listado de docentes');

        // Al acceder como esa cuenta solo se ve el módulo de incidencias.
        $this->client->request('GET', '/?_switch_user=' . $w['paco']->getUsername());
        $this->client->followRedirects();
        $this->client->request('GET', '/partes');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/incidencias');
        self::assertResponseIsSuccessful();
    }

    public function testTheOldDocentesAddressesRedirectToUsuarios(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin']);

        $this->client->request('GET', '/admin/docentes');
        self::assertResponseRedirects('/admin/usuarios', 301);
        $this->client->request('GET', '/admin/docentes/personal-no-docente/nuevo');
        self::assertResponseRedirects('/admin/usuarios/personal-no-docente/nuevo', 301);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function world(): array
    {
        $seq   = ++self::$seq;
        $admin = (new Teacher(new PersonName('Admin', 'Global')))->setUsername("admin.nda.$seq")->setAdmin(true);
        $doc   = (new Teacher(new PersonName('Dora', 'Docente')))->setUsername("dora.nda.$seq");
        $paco  = (new Teacher(new PersonName('Paco', 'Conserje')))->setUsername("paco.nda.$seq")->setEmail("paco.nda$seq@example.org")->setNonTeaching(true);
        $uno   = (new EducationalCentre())->setCode("4600{$seq}66")->setName('IES Uno')->setCity('Sevilla');
        $dos   = (new EducationalCentre())->setCode("4601{$seq}66")->setName('IES Dos')->setCity('Cádiz');
        $type  = (new IssueType())->setEducationalCentre($uno)->setName('Mantenimiento')->addManager($paco);

        $this->persist($admin, $doc, $paco, $uno, $dos, $type);
        $uno->addNonTeachingStaff($paco);
        $this->flush();

        return compact('admin', 'doc', 'paco', 'uno', 'dos', 'type');
    }
}
