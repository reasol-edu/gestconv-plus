<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\RestoreState;
use App\Tests\Integration\ControllerTestCase;
use App\Tests\Support\RecordingRestoreLauncher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Restaurar desde la web una copia guardada en el servidor, y la protección de la aplicación mientras dura. */
class SystemRestoreTest extends ControllerTestCase
{
    private const string PASSWORD = 'Contraseña-segura-1';

    private function library(): BackupLibrary
    {
        /** @var BackupLibrary $library */
        $library = self::getContainer()->get(BackupLibrary::class);

        return $library;
    }

    private function state(): RestoreState
    {
        /** @var RestoreState $state */
        $state = self::getContainer()->get(RestoreState::class);

        return $state;
    }

    private function loginAsAdminWithPassword(): Teacher
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $admin  = (new Teacher(new PersonName('Admin', 'Sys')))->setUsername('admin.restore.' . uniqid('', false))->setAdmin(true);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $this->persist($admin);
        $this->loginAs($admin);

        return $admin;
    }

    /** Guarda una copia en el servidor y devuelve su nombre. */
    private function aCopy(): string
    {
        return $this->library()->save()->name;
    }

    /** @return array<string, string> los campos del formulario de confirmación, con el token */
    private function confirmForm(string $name, string $password = self::PASSWORD, string $word = 'RESTAURAR'): array
    {
        $crawler = $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');

        return [
            '_token'   => (string) $crawler->filter('form input[name="_token"]')->last()->attr('value'),
            'password' => $password,
            'confirm'  => $word,
        ];
    }

    // ── guardar, listar y eliminar ───────────────────────────────────────────

    public function testSavingACopyOnTheServerListsItWithItsRestoreButton(): void
    {
        $this->loginAsAdminWithPassword();
        $crawler = $this->client->request('GET', '/admin/sistema');
        $token   = $crawler->filter('form[action$="/copias/guardar"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/sistema/copias/guardar', ['_token' => $token]);
        self::assertResponseRedirects('/admin/sistema');

        $crawler = $this->client->request('GET', '/admin/sistema');
        self::assertSelectorTextContains('main, body', 'gestconv-copia-');
        self::assertCount(1, $crawler->filter('a[href*="/copias/"][href$="/restaurar"]'));
    }

    public function testSavingNeedsAValidToken(): void
    {
        $this->loginAsAdminWithPassword();

        $this->client->request('POST', '/admin/sistema/copias/guardar', ['_token' => 'invalido']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->library()->list());
    }

    public function testACopyCanBeDeleted(): void
    {
        $this->loginAsAdminWithPassword();
        $name    = $this->aCopy();
        $crawler = $this->client->request('GET', '/admin/sistema');
        $token   = $crawler->filter('form[action$="/eliminar"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects('/admin/sistema');
        self::assertSame([], $this->library()->list());
    }

    public function testDeletingNeedsAValidTokenAndAnExistingCopy(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/eliminar', ['_token' => 'invalido']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->library()->list());

        $this->client->request('POST', '/admin/sistema/copias/nunca-existio.zip/eliminar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    // ── revisión y confirmación ──────────────────────────────────────────────

    public function testTheReviewPageShowsTheCopyAndWhatWillHappen(): void
    {
        $admin = $this->loginAsAdminWithPassword();
        $name  = $this->aCopy();

        $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Restaurar una copia de seguridad');
        self::assertSelectorTextContains('main, body', $name);
        self::assertSelectorTextContains('main, body', 'Esto sustituye todos los datos');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="confirm"]');
        self::assertSelectorNotExists('.text-red-700[role="alert"]');
        // El docente que la pide sí figura en la copia (se guardó con él ya creado): no hay aviso de usuario ausente.
        self::assertStringNotContainsString('NO figura en esa copia', (string) $this->client->getResponse()->getContent());
        self::assertSame([], RecordingRestoreLauncher::$launched, 'Mirar no restaura.');
        self::assertFalse($this->state()->isRunning());
        unset($admin);
    }

    public function testItWarnsWhenTheCurrentUserIsNotInTheCopy(): void
    {
        $name = $this->aCopy();   // la copia se hace antes de que exista este administrador
        $this->loginAsAdminWithPassword();

        $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');

        self::assertSelectorTextContains('main, body', 'NO figura en esa copia');
    }

    public function testAWrongPasswordOrWordDoesNotRestore(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', $this->confirmForm($name, 'otra-contraseña'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'La contraseña no es correcta');

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', $this->confirmForm($name, self::PASSWORD, 'restaurar'));
        self::assertSelectorTextContains('[role="alert"]', 'palabra de confirmación');

        self::assertSame([], RecordingRestoreLauncher::$launched);
        self::assertFalse($this->state()->isRunning(), 'Ningún intento fallido deja el bloqueo puesto.');
    }

    public function testAnInvalidTokenIsForbidden(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', ['_token' => 'x', 'password' => self::PASSWORD, 'confirm' => 'RESTAURAR']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], RecordingRestoreLauncher::$launched);
    }

    public function testAnAccountWithoutAPasswordCannotConfirmFromTheWeb(): void
    {
        $admin = (new Teacher(new PersonName('Admin', 'Externo')))->setUsername('admin.externo')->setAdmin(true);
        $this->persist($admin);
        $this->loginAs($admin);
        $name = $this->aCopy();

        $crawler = $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');

        self::assertSelectorTextContains('main, body', 'no tiene contraseña propia');
        self::assertCount(0, $crawler->filter('input[name="password"]'));
    }

    // ── lanzar la restauración ───────────────────────────────────────────────

    public function testAValidConfirmationLaunchesTheRestoreAndTheSiteGoesIntoMaintenance(): void
    {
        $admin = $this->loginAsAdminWithPassword();
        $name  = $this->aCopy();

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', $this->confirmForm($name));

        self::assertResponseRedirects('/admin/sistema');
        self::assertCount(1, RecordingRestoreLauncher::$launched);
        self::assertSame($this->library()->directory() . '/' . $name, RecordingRestoreLauncher::$launched[0]['zip']);
        self::assertSame($admin->getUserIdentifier(), RecordingRestoreLauncher::$launched[0]['by']);
        self::assertTrue($this->state()->isRunning(), 'El bloqueo lo toma la web antes de lanzar el proceso.');

        // Y mientras dura, TODO el mundo (también quien la pidió) ve la página de mantenimiento.
        $this->client->request('GET', '/admin/sistema');
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('h1', 'Restaurando una copia de seguridad');
        self::assertSame('15', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringNotContainsString($name, (string) $this->client->getResponse()->getContent(), 'La página pública no dice qué copia ni quién restaura.');
        self::assertStringNotContainsString($admin->getUserIdentifier(), (string) $this->client->getResponse()->getContent());
    }

    public function testIfTheProcessCannotBeLaunchedTheLockIsReleased(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        RecordingRestoreLauncher::$failWith = 'proc_open desactivado';

        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', $this->confirmForm($name));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'proc_open desactivado');
        self::assertFalse($this->state()->isRunning(), 'Si nadie la va a terminar, no se deja el sitio en 503.');
        $this->client->request('GET', '/admin/sistema');
        self::assertResponseIsSuccessful();
    }

    public function testItRefusesWhileAnotherRestoreIsRunning(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        $form = $this->confirmForm($name);
        $this->state()->acquire('otro', 'x.zip');
        $this->state()->attachPid((int) getmypid());

        // La aplicación ya está en mantenimiento para todos: ni siquiera se llega a la pantalla.
        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', $form);

        self::assertResponseStatusCodeSame(503);
        self::assertSame([], RecordingRestoreLauncher::$launched);
    }

    public function testWhereProcessesCannotBeLaunchedOnlyTheConsoleRemains(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        RecordingRestoreLauncher::$available = false;

        $crawler = $this->client->request('GET', '/admin/sistema');
        self::assertSelectorTextContains('main, body', 'solo se puede hacer desde la línea de órdenes');
        self::assertCount(0, $crawler->filter('a[href$="/restaurar"]'));

        $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');
        self::assertResponseRedirects('/admin/sistema');
    }

    public function testACopyThatCannotBeRestoredHereHasNoRestorePage(): void
    {
        $this->loginAsAdminWithPassword();
        $name = $this->aCopy();
        $path = $this->library()->directory() . '/' . $name;
        $zip  = new \ZipArchive();
        $zip->open($path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['platform'] = 'mysql';
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();

        $crawler = $this->client->request('GET', '/admin/sistema');
        self::assertSelectorTextContains('main, body', 'misma plataforma');
        self::assertCount(0, $crawler->filter('a[href$="/restaurar"]'));

        $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');
        self::assertResponseRedirects('/admin/sistema');
    }

    public function testOnlyGlobalAdminsCanDoAnyOfThis(): void
    {
        $name    = $this->aCopy();
        $centre  = (new EducationalCentre())->setCode('41000888')->setName('IES')->setCity('Sevilla');
        $teacher = (new Teacher(new PersonName('Cen', 'Admin')))->setUsername('centre.admin.restore');
        $centre->addAdmin($teacher);
        $this->persist($centre, $teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/admin/sistema/copias/' . $name . '/restaurar');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/restaurar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/sistema/copias/guardar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/sistema/copias/' . $name . '/eliminar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], RecordingRestoreLauncher::$launched);
    }

    // ── la protección de la aplicación ───────────────────────────────────────

    public function testWhileRestoringThePageIsServedWithoutTouchingTheDatabase(): void
    {
        $this->state()->acquire('ana', 'copia.zip');
        $this->state()->attachPid((int) getmypid());

        // El registro de consultas de Doctrine arrastra las de la preparación del test (esquema y ajustes): se vacía justo antes.
        /** @var \Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->client->request('GET', '/login');

        self::assertResponseStatusCodeSame(503);
        self::assertSame([], $holder->getData()['default'] ?? [], 'La tabla de usuarios se está vaciando: la página 503 no puede consultar la base de datos.');
    }

    public function testStaticFilesAreNotBlocked(): void
    {
        $this->state()->acquire('ana', 'copia.zip');
        $this->state()->attachPid((int) getmypid());

        $this->client->request('GET', '/static/favicon.svg');

        self::assertNotSame(503, $this->client->getResponse()->getStatusCode());
    }

    public function testSessionsCreatedBeforeTheLastRestoreAreClosed(): void
    {
        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('ana.sesion');
        $this->persist($teacher);
        $this->loginAs($teacher);
        $this->client->request('GET', '/');
        self::assertNotSame('/login', $this->client->getResponse()->headers->get('Location'), 'Antes de la restauración la sesión vale.');

        // Una restauración terminó después de que esta sesión se creara.
        $dir = self::getContainer()->getParameter('app.restore_state_dir');
        self::assertIsString($dir);
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/restored-at', (string) (time() + 60));

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testSessionsCreatedAfterTheLastRestoreSurvive(): void
    {
        $dir = self::getContainer()->getParameter('app.restore_state_dir');
        self::assertIsString($dir);
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/restored-at', (string) (time() - 3600));

        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('ana.sesion2');
        $this->persist($teacher);
        $this->loginAs($teacher);
        $this->client->request('GET', '/');

        self::assertNotSame('/login', $this->client->getResponse()->headers->get('Location'));
        self::assertNotSame(503, $this->client->getResponse()->getStatusCode());
    }
}
