<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** Copia de seguridad descargable desde el panel de administración. */
class SystemControllerTest extends ControllerTestCase
{
    // ── acceso ────────────────────────────────────────────────────────────────

    public function testAnonymousUsersAreRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/sistema');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('POST', '/admin/sistema/copia');
        self::assertResponseRedirects();
    }

    public function testACentreAdminWithoutTheGlobalRoleIsForbidden(): void
    {
        $centre  = (new EducationalCentre())->setCode('41000777')->setName('IES')->setCity('Sevilla');
        $teacher = (new Teacher(new PersonName('Cen', 'Admin')))->setUsername('centre.admin.sys');
        $centre->addAdmin($teacher);
        $this->persist($centre, $teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/admin/sistema');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/sistema/copia', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePageOffersTheDownloadToAnAdmin(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/sistema');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Copia de seguridad y versión');
        self::assertCount(1, $crawler->filter('form[action$="/admin/sistema/copia"] input[name="_token"]'));
        self::assertSelectorTextContains('main, body', 'Estás usando GestConv+ ');
    }

    public function testTheDashboardLinksToThePage(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/admin');

        self::assertSelectorExists('a[href="/admin/sistema"]');
    }

    // ── descarga ──────────────────────────────────────────────────────────────

    public function testADownloadWithoutAValidTokenIsRefused(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/admin/sistema/copia', ['_token' => 'token-invalido']);
        self::assertResponseStatusCodeSame(403);

        // Nunca por GET: un enlace no puede lanzar una copia.
        $this->client->request('GET', '/admin/sistema/copia');
        self::assertResponseStatusCodeSame(405);
    }

    public function testTheDownloadIsAZipWithTheManifestAndTheTables(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/sistema');
        $token   = $crawler->filter('form[action$="/admin/sistema/copia"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/sistema/copia', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=gestconv-copia-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $zipFile = tempnam(sys_get_temp_dir(), 'gc-dl-');
        file_put_contents($zipFile, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipFile));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sqlite', $manifest['platform']);
        self::assertArrayHasKey('teacher', $manifest['tables']);
        self::assertNotFalse($zip->locateName('data/teacher.ndjson'));
        $zip->close();
        unlink($zipFile);
    }

    public function testTheDownloadIsLoggedInTheActivityLog(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';

        try {
            $this->loginAsAdmin();
            $crawler = $this->client->request('GET', '/admin/sistema');
            $token   = $crawler->filter('form[action$="/admin/sistema/copia"] input[name="_token"]')->attr('value');
            $this->client->request('POST', '/admin/sistema/copia', ['_token' => $token]);

            self::assertResponseIsSuccessful();
            $this->em->clear();
            $actions = array_map(static fn (ActivityLog $log): string => $log->getActionType(), $this->em->getRepository(ActivityLog::class)->findAll());
            self::assertContains('backup.downloaded', $actions);
        } finally {
            putenv('APP_LOG=false');
            $_ENV['APP_LOG']    = 'false';
            $_SERVER['APP_LOG'] = 'false';
        }
    }

    private function loginAsAdmin(): void
    {
        $admin = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.sys.' . uniqid('', false))->setAdmin(true);
        $this->persist($admin);
        $this->loginAs($admin);
    }
}
