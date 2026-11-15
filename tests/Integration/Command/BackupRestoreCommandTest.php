<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\EducationalCentre;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\RestoreState;
use App\Service\Backup\SchemaMigrator;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Uid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** `app:backup:restore`: bloqueo, copia previa, resultado, sesiones y salida de emergencia. */
class BackupRestoreCommandTest extends RepositoryTestCase
{
    private BackupLibrary $library;
    private RestoreState $state;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BackupLibrary $library */
        $library = self::getContainer()->get(BackupLibrary::class);
        /** @var RestoreState $state */
        $state = self::getContainer()->get(RestoreState::class);
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');

        $this->library = $library;
        $this->state   = $state;
        $this->db      = $db;
    }

    /** @param array<string, mixed> $input */
    private function restoreWith(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:backup:restore'));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function centres(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM educational_centre');
    }

    private function oneCopyAndOneMoreCentre(): string
    {
        $this->persist((new EducationalCentre())->setCode('41000001')->setName('IES Uno')->setCity('Sevilla'));
        $copy = $this->library->save();
        $this->persist((new EducationalCentre())->setCode('41000002')->setName('IES Dos')->setCity('Cádiz'));
        self::assertSame(2, $this->centres());

        return $this->library->path($copy);
    }

    public function testItRestoresRecordsTheResultAndClosesTheSessions(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();

        $tester = $this->restoreWith(['file' => $path, '--force' => true, '--by' => 'ana']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $this->centres(), 'Vuelve el estado de la copia.');
        self::assertFalse($this->state->isRunning(), 'El bloqueo se libera al terminar.');
        self::assertNotNull($this->state->restoredAt(), 'Se anota para cerrar las sesiones anteriores.');
        $result = $this->state->lastResult();
        self::assertTrue($result['ok'] ?? false);
        self::assertSame('ana', $result['by']);
    }

    public function testWithoutForceAndWithoutInteractionItRefuses(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();

        $tester = $this->restoreWith(['file' => $path]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(2, $this->centres(), 'No cambia nada.');
        self::assertNull($this->state->restoredAt());
    }

    public function testItRefusesWhileAnotherRestorationIsRunningAndDoesNotRemoveItsLock(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();
        $this->state->acquire('otro', 'x.zip');
        $this->state->attachPid((int) getmypid());

        $tester = $this->restoreWith(['file' => $path, '--force' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(2, $this->centres());
        self::assertTrue($this->state->isRunning(), 'No quita el bloqueo de quien sí está restaurando.');
    }

    public function testALockHeldByTheWebIsReleasedEvenIfTheCopyIsRejected(): void
    {
        // Una copia que no es un zip: la validación falla, pero el bloqueo que tomó la web debe liberarse (si no, el sitio se queda en 503).
        @mkdir($this->library->directory(), 0700, true);
        $bad = $this->library->directory() . '/mala.zip';
        file_put_contents($bad, 'esto no es un zip');
        $this->state->acquire('ana', 'mala.zip');

        $tester = $this->restoreWith(['file' => $bad, '--force' => true, '--lock-held' => true, '--by' => 'ana']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertFalse($this->state->isRunning());
        $result = $this->state->lastResult();
        self::assertFalse($result['ok'] ?? true);
        self::assertNull($this->state->restoredAt(), 'Una restauración fallida no cierra las sesiones.');
    }

    public function testAFailedRestoreAfterTheValidationKeepsTheDataAndReleasesTheLock(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();
        $zip  = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('data/educational_centre.ndjson', "{esto no es json\n");
        $zip->close();
        $this->state->acquire('ana', 'copia.zip');

        $tester = $this->restoreWith(['file' => $path, '--force' => true, '--lock-held' => true, '--by' => 'ana']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(2, $this->centres(), 'La transacción deshace el vaciado.');
        self::assertFalse($this->state->isRunning());
        self::assertFalse($this->state->lastResult()['ok'] ?? true);
        self::assertNull($this->state->restoredAt());
    }

    public function testThePreviousBackupIsSavedBeforeRestoring(): void
    {
        $path = $this->oneCopyAndOneMoreCentre();

        $this->restoreWith(['file' => $path, '--force' => true, '--previous-backup' => true]);

        $names = array_map(static fn ($c): string => $c->name, $this->library->list());
        self::assertCount(2, $names);
        self::assertNotEmpty(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'gestconv-antes-de-restaurar-')));
    }

    /** @return string la ruta de una copia hecha con el esquema en la migración 084, estando la base de datos ya en la última (con otro centro más) */
    private function oldSchemaCopyWhileTheDatabaseIsAhead(): string
    {
        /** @var SchemaMigrator $migrator */
        $migrator = self::getContainer()->get(SchemaMigrator::class);
        $migrator->rebuildAt('DoctrineMigrations\Version20260101000084');
        $this->insertCentre('41000001', 'IES Antiguo');
        $copy = $this->library->save();
        $migrator->migrateToLatest();
        $this->insertCentre('41000002', 'IES Nuevo');
        self::assertSame(['IES Antiguo', 'IES Nuevo'], $this->db->fetchFirstColumn('SELECT name FROM educational_centre ORDER BY code'));

        return $this->library->path($copy);
    }

    private function insertCentre(string $code, string $name): void
    {
        $this->db->executeStatement('INSERT INTO educational_centre (id, code, name, city) VALUES (?, ?, ?, ?)', [$this->db->getDatabasePlatform() instanceof AbstractMySQLPlatform ? Uuid::v7()->toBinary() : Uuid::v7()->toRfc4122(), $code, $name, 'Sevilla']);
    }

    public function testACopyOfAnOlderSchemaAlwaysSavesThePreviousBackupAndLeavesTheDatabaseUpToDate(): void
    {
        $path = $this->oldSchemaCopyWhileTheDatabaseIsAhead();

        // Sin --previous-backup: con esquema distinto la copia previa es obligatoria.
        $tester = $this->restoreWith(['file' => $path, '--force' => true, '--by' => 'ana']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['IES Antiguo'], $this->db->fetchFirstColumn('SELECT name FROM educational_centre'));
        /** @var SchemaMigrator $migrator */
        $migrator = self::getContainer()->get(SchemaMigrator::class);
        self::assertSame($migrator->latest(), $this->db->fetchOne('SELECT MAX(version) FROM doctrine_migration_versions'));
        $names = array_map(static fn ($c): string => $c->name, $this->library->list());
        self::assertNotEmpty(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'gestconv-antes-de-restaurar-')));
        self::assertTrue($this->state->lastResult()['ok'] ?? false);
    }

    public function testIfRestoringAnOlderSchemaFailsHalfwayItGoesBackToThePreviousData(): void
    {
        $path = $this->oldSchemaCopyWhileTheDatabaseIsAhead();
        $zip  = new \ZipArchive();
        $zip->open($path);
        $zip->addFromString('data/setting_definition.ndjson', "{esto no es json\n");
        $zip->close();
        $this->state->acquire('ana', 'copia.zip');

        $tester = $this->restoreWith(['file' => $path, '--force' => true, '--lock-held' => true, '--by' => 'ana']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('recuperado', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(['IES Antiguo', 'IES Nuevo'], $this->db->fetchFirstColumn('SELECT name FROM educational_centre ORDER BY code'), 'Vuelven los datos de antes.');
        self::assertFalse($this->state->isRunning());
        self::assertFalse($this->state->lastResult()['ok'] ?? true);
        self::assertNull($this->state->restoredAt());
    }

    public function testUnlockRemovesAStuckLock(): void
    {
        $this->state->acquire('ana', 'copia.zip');
        $this->state->attachPid((int) getmypid());
        self::assertTrue($this->state->isRunning());

        $tester = $this->restoreWith(['--unlock' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFalse($this->state->isRunning());
        self::assertSame(0, $this->restoreWith(['--unlock' => true])->getStatusCode(), 'Sin bloqueo tampoco es un error.');
    }

    public function testWithoutAFileItAsksForOne(): void
    {
        self::assertSame(1, $this->restoreWith([])->getStatusCode());
    }
}
