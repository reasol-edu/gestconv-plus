<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class EducationalCentreControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToAdmin(): void
    {
        $admin = $this->makeAdmin('admin.1');
        $this->persist($admin);
        $this->loginAs($admin);

        $this->client->request('GET', '/admin/centros');

        self::assertResponseIsSuccessful();
    }

    public function testIndexDeniesNonAdmin(): void
    {
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/admin/centros');

        self::assertResponseStatusCodeSame(403);
    }

    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewGetRendersForm(): void
    {
        $admin = $this->makeAdmin('admin.1');
        $this->persist($admin);
        $this->loginAs($admin);

        $this->client->request('GET', '/admin/centros/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewPostCreatesCentreAndRedirectsToIndex(): void
    {
        $admin = $this->makeAdmin('admin.1');
        $this->persist($admin);
        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/centros/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $token,
            'code'   => '41000001',
            'name'   => 'IES Test',
            'city'   => 'Sevilla',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/centros', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testNewPostWithInvalidCsrfIsDenied(): void
    {
        $admin = $this->makeAdmin('admin.1');
        $this->persist($admin);
        $this->loginAs($admin);

        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => 'token-invalido',
            'code'   => '41000001',
            'name'   => 'IES Test',
            'city'   => 'Sevilla',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewPostWithEmptyNameRendersFormAgain(): void
    {
        $admin = $this->makeAdmin('admin.1');
        $this->persist($admin);
        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/centros/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $token,
            'code'   => '41000001',
            'name'   => '',
            'city'   => 'Sevilla',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testNewPostWithDuplicateCodeRendersFormAgain(): void
    {
        $admin  = $this->makeAdmin('admin.1');
        $centre = $this->makeCentre('41000001');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/centros/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $token,
            'code'   => '41000001',
            'name'   => 'IES Duplicado',
            'city'   => 'Malaga',
        ]);

        self::assertResponseIsSuccessful();
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        $admin  = $this->makeAdmin('admin.1');
        $centre = $this->makeCentre('41000001');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $this->client->request('GET', '/admin/centros/' . $centre->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditPostSavesChangesAndRedirects(): void
    {
        $admin  = $this->makeAdmin('admin.1');
        $centre = $this->makeCentre('41000001');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/admin/centros/' . $centreId);
        $token    = $crawler->filter('form')->first()->filter('[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId, [
            '_token' => $token,
            'code'   => '41000001',
            'name'   => 'IES Modificado',
            'city'   => 'Malaga',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(EducationalCentre::class, $centre->getId());
        self::assertSame('IES Modificado', $updated->getName());
        self::assertSame('Malaga', $updated->getCity());
    }

    public function testEditPostWithInvalidCsrfIsDenied(): void
    {
        $admin  = $this->makeAdmin('admin.1');
        $centre = $this->makeCentre('41000001');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/admin/centros/' . $centreId, [
            '_token' => 'token-invalido',
            'code'   => '41000001',
            'name'   => 'Hack',
            'city'   => 'Sevilla',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteCentreDeletesEntityAndRedirectsToIndex(): void
    {
        $admin  = $this->makeAdmin('admin.1');
        $centre = $this->makeCentre('41000001');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/admin/centros/' . $centreId);
        $token    = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/centros', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        self::assertNull($this->em->find(EducationalCentre::class, $centre->getId()));
    }

    public function testDeleteCentreWithInvalidCsrfIsDenied(): void
    {
        $admin  = $this->makeAdmin('admin.1');
        $centre = $this->makeCentre('41000001');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/admin/centros/' . $centreId . '/eliminar', ['_token' => 'token-invalido']);

        self::assertResponseStatusCodeSame(403);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeAdmin(string $username): Teacher
    {
        return (new Teacher(new PersonName('Admin', 'User')))->setUsername($username)->setAdmin(true);
    }

    private function makeCentre(string $code): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
    }
}
