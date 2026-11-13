<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SettingFile;
use App\Entity\Teacher;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupManifest;
use App\Service\Backup\DatabaseBackup;
use App\Service\Backup\DatabaseRestore;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\DBAL\Connection;

/** Copia de seguridad portable y su restauración (SQLite, la base de datos de los tests). */
class DatabaseBackupRestoreTest extends RepositoryTestCase
{
    private DatabaseBackup $backup;
    private DatabaseRestore $restore;
    private Connection $db;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DatabaseBackup $backup */
        $backup = self::getContainer()->get(DatabaseBackup::class);
        /** @var DatabaseRestore $restore */
        $restore = self::getContainer()->get(DatabaseRestore::class);
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');

        $this->backup  = $backup;
        $this->restore = $restore;
        $this->db      = $db;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testTheBackupLooksInsideTheDatabaseAndSkipsTransientTables(): void
    {
        $this->world();
        // La tabla de la cola de mensajes ya existe en el esquema de pruebas.
        $this->db->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('correo pendiente', '[]', 'default', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $path     = $this->zipPath();
        $manifest = $this->backup->create($path);

        self::assertSame(BackupManifest::FORMAT, $manifest->format);
        self::assertSame('sqlite', $manifest->platform);
        self::assertSame(1, $manifest->tables['educational_centre']['rows']);
        self::assertSame(1, $manifest->tables['academic_year']['rows']);
        self::assertSame(1, $manifest->tables['group_tutor']['rows'], 'Las tablas de unión sin entidad también entran.');
        self::assertSame(1, $manifest->tables['setting_file']['rows']);
        self::assertArrayNotHasKey('messenger_messages', $manifest->tables, 'Restaurar la cola reenviaría correos ya enviados.');
        self::assertContains('messenger_messages', $manifest->excluded);
        self::assertSame(1, $manifest->tables['teacher']['rows']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertNotFalse($zip->locateName('manifest.json'));
        self::assertNotFalse($zip->locateName('data/academic_year.ndjson'));
        self::assertFalse($zip->locateName('data/messenger_messages.ndjson'));
        $zip->close();
    }

    public function testARestoreBringsEverythingBackIncludingBinariesAndRawByteIds(): void
    {
        $w   = $this->world();
        $zip = $this->zipPath();
        $this->backup->create($zip);

        // Cambios posteriores a la copia: se borra un docente y un fichero, se crea otro centro.
        $groupId = $w['group']->getId();
        $fileId  = $w['file']->getId();
        $other   = (new EducationalCentre())->setCode('41009999')->setName('Otro')->setCity('Cádiz');
        $this->persist($other);
        $this->em->remove($w['file']);
        $this->em->remove($w['group']);
        $this->flush();

        $restored = $this->restore->restore($zip);
        $this->em->clear();

        self::assertSame(1, $restored['group']);
        // Como los guarda Doctrine en SQLite: un texto con los 16 bytes del UUID (no un BLOB).
        self::assertSame('text', $this->db->fetchOne('SELECT typeof(id) FROM "group"'));

        self::assertSame(1, $restored['educational_centre'], 'La restauración vacía lo que no estaba en la copia.');
        self::assertNull($this->em->getRepository(EducationalCentre::class)->findOneBy(['code' => '41009999']));

        // Los ids de SQLite son 16 bytes en bruto: si se hubieran restaurado como BLOB, find() no los encontraría.
        $centre = $this->em->getRepository(EducationalCentre::class)->findOneBy(['code' => '41000001']);
        self::assertNotNull($centre);
        self::assertTrue($w['centre']->getId()->equals($centre->getId()));
        $group = $this->em->find(Group::class, $groupId);
        self::assertNotNull($group, 'Un grupo borrado después de la copia vuelve, y se encuentra por su id.');
        self::assertCount(1, $group->getTutors(), 'La tabla de unión group_tutor vuelve.');

        $file = $this->em->find(SettingFile::class, $fileId);
        self::assertNotNull($file);
        self::assertSame($w['bytes'], $file->getContent(), 'Los binarios vuelven idénticos.');
        self::assertNotNull($centre->getActiveAcademicYear(), 'Las referencias circulares (centro ↔ curso) vuelven.');

        // Lo restaurado es utilizable: se puede guardar algo que lo referencia.
        $next = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $this->persist($next);
        self::assertNotNull($this->em->getRepository(AcademicYear::class)->findOneBy(['name' => '2026-2027']));
    }

    public function testAFailedRestoreChangesNothing(): void
    {
        $this->world();
        $zip = $this->zipPath();
        $this->backup->create($zip);

        // Se corrompe una fila de la copia.
        $broken = $this->zipPath();
        $this->corruptRows($zip, $broken, 'academic_year');

        $this->persist((new EducationalCentre())->setCode('41009999')->setName('Otro')->setCity('Cádiz'));
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM educational_centre');

        try {
            $this->restore->restore($broken);
            self::fail('Debería haber fallado.');
        } catch (BackupException $e) {
            self::assertStringContainsString('no se ha cambiado nada', $e->getMessage());
        }

        self::assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM educational_centre'), 'La transacción deshace el vaciado.');
    }

    public function testACopyOfAnotherPlatformOrSchemaIsRefusedBeforeTouchingAnything(): void
    {
        $this->world();
        $this->db->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY, executed_at DATETIME, execution_time INTEGER)');
        $this->db->executeStatement("INSERT INTO doctrine_migration_versions (version) VALUES ('DoctrineMigrations\\Version20260101000083')");

        $manifest = $this->backup->create($this->zipPath());
        self::assertSame('DoctrineMigrations\Version20260101000083', $manifest->schemaVersion);
        self::assertSame([], $this->restore->problems($manifest));

        $otherPlatform = new BackupManifest(1, '1.0.0', $manifest->schemaVersion, 'postgresql', $manifest->createdAt, $manifest->tables, []);
        self::assertStringContainsString('misma plataforma', implode(' ', $this->restore->problems($otherPlatform)));

        $older = new BackupManifest(1, '1.30.0', 'DoctrineMigrations\Version20260101000079', 'sqlite', $manifest->createdAt, $manifest->tables, []);
        $text  = implode(' ', $this->restore->problems($older));
        self::assertStringContainsString('migra la base de datos', $text);
        self::assertStringContainsString('1.30.0', $text);

        $withoutTable = $manifest->tables;
        unset($withoutTable['group_tutor']);
        self::assertStringContainsString('group_tutor', implode(' ', $this->restore->problems(new BackupManifest(1, '1', $manifest->schemaVersion, 'sqlite', $manifest->createdAt, $withoutTable, []))));

        $this->expectException(BackupException::class);
        $this->restore->restore($this->zipWithManifest($older));
    }

    public function testAFileThatIsNotACopyIsRejected(): void
    {
        $notZip = $this->tmpFile();
        file_put_contents($notZip, 'esto no es un zip');
        try {
            $this->restore->readManifest($notZip);
            self::fail();
        } catch (BackupException $e) {
            self::assertStringContainsString('zip', $e->getMessage());
        }

        $empty = $this->tmpFile();
        $zip   = new \ZipArchive();
        $zip->open($empty, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        $zip->addFromString('otra-cosa.txt', 'x');
        $zip->close();
        $this->expectException(BackupException::class);
        $this->restore->readManifest($empty);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, group: Group, file: SettingFile, bytes: string} */
    private function world(): array
    {
        $centre = (new EducationalCentre())->setCode('41000001')->setName('IES Uno')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $teacher = (new Teacher(new PersonName('Ana', 'López')))->setUsername('ana.lopez')->setPassword('x');
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1A')->setCourse($course)->addTutor($teacher);

        // Bytes que no son UTF-8 válido (cabecera PNG).
        $bytes = "\x89PNG\r\n\x1a\n\x00\xff\xfe" . random_bytes(32);
        $file  = new SettingFile(hash('sha256', $bytes), $bytes, 'image/png', strlen($bytes));

        $this->persist($centre, $year, $teacher, $course, $group, $file);

        return ['centre' => $centre, 'group' => $group, 'file' => $file, 'bytes' => $bytes];
    }

    private function tmpFile(): string
    {
        $path = sys_get_temp_dir() . '/gc-test-' . bin2hex(random_bytes(5)) . '.zip';
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function zipPath(): string
    {
        return $this->tmpFile();
    }

    /** Copia `$from` a `$to` con basura en una de las tablas. */
    private function corruptRows(string $from, string $to, string $table): void
    {
        copy($from, $to);
        $zip = new \ZipArchive();
        $zip->open($to);
        $zip->addFromString('data/' . $table . '.ndjson', "{esto no es json\n");
        $zip->close();
    }

    private function zipWithManifest(BackupManifest $manifest): string
    {
        $path = $this->tmpFile();
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest->toArray(), JSON_THROW_ON_ERROR));
        $zip->close();

        return $path;
    }
}
