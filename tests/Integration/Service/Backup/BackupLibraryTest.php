<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\BackupManifest;
use App\Tests\Integration\RepositoryTestCase;

/** Copias guardadas en el servidor: listar, localizar por nombre exacto, guardar, borrar. */
class BackupLibraryTest extends RepositoryTestCase
{
    private BackupLibrary $library;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BackupLibrary $library */
        $library       = self::getContainer()->get(BackupLibrary::class);
        $this->library = $library;
        $this->dir     = $library->directory();
    }

    public function testSavingCreatesAFileThatIsListedAndRestorable(): void
    {
        $this->persist((new EducationalCentre())->setCode('41000001')->setName('IES')->setCity('Sevilla'));

        $stored = $this->library->save();

        self::assertMatchesRegularExpression('/^gestconv-copia-\d{8}-\d{6}\.zip$/', $stored->name);
        self::assertFileExists($this->dir . '/' . $stored->name);
        self::assertSame('sqlite', $stored->manifest?->platform);
        self::assertTrue($stored->isRestorable());

        $list = $this->library->list();
        self::assertCount(1, $list);
        self::assertSame($stored->name, $list[0]->name);
    }

    public function testACopyIsOnlyFoundByItsExactName(): void
    {
        $stored = $this->library->save();

        self::assertNotNull($this->library->find($stored->name));
        self::assertNull($this->library->find('no-existe.zip'));
        self::assertNull($this->library->find($stored->name . '.bak'));
        self::assertNull($this->library->find('GESTCONV-COPIA-X.zip'));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousNames(): iterable
    {
        yield 'recorrido' => ['../secreto.zip'];
        yield 'subcarpeta' => ['sub/copia.zip'];
        yield 'ruta absoluta' => ['/etc/passwd.zip'];
        yield 'con bytes nulos' => ["copia.zip\0.txt"];
        yield 'sin extensión' => ['copia'];
        yield 'oculto' => ['.oculto.zip'];
        yield 'con espacios' => ['mi copia.zip'];
        yield 'barra invertida' => ['..\\copia.zip'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousNames')]
    public function testNamesThatCouldEscapeTheFolderAreRefused(string $name): void
    {
        // Un fichero fuera de la carpeta con un nombre «válido» al que se intenta llegar.
        file_put_contents(dirname($this->dir) . '/secreto.zip', 'x');
        @mkdir($this->dir, 0700, true);

        self::assertNull($this->library->find($name));
        self::assertFalse($this->library->delete($name));
        self::assertFileExists(dirname($this->dir) . '/secreto.zip');

        @unlink(dirname($this->dir) . '/secreto.zip');
    }

    public function testASymlinkLeadingOutsideTheFolderIsRefused(): void
    {
        @mkdir($this->dir, 0700, true);
        $outside = sys_get_temp_dir() . '/gc-outside-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($outside, 'no soy una copia');
        symlink($outside, $this->dir . '/enlace.zip');

        self::assertNull($this->library->find('enlace.zip'), 'Un enlace que apunta fuera de la carpeta no cuenta.');
        self::assertFalse($this->library->delete('enlace.zip'));
        self::assertFileExists($outside);

        @unlink($this->dir . '/enlace.zip');
        @unlink($outside);
    }

    public function testAFileThatIsNotACopyIsListedAsNotRestorable(): void
    {
        @mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/basura.zip', 'esto no es un zip');

        $stored = $this->library->find('basura.zip');

        self::assertNotNull($stored);
        self::assertNull($stored->manifest);
        self::assertFalse($stored->isRestorable());
        self::assertNotSame([], $stored->problems);
    }

    public function testACopyFromAnotherPlatformIsListedWithItsProblem(): void
    {
        $stored = $this->library->save();
        $path   = $this->library->path($stored);

        $zip = new \ZipArchive();
        $zip->open($path);
        $data = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['platform'] = 'postgresql';
        $zip->addFromString('manifest.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $found = $this->library->find($stored->name);
        self::assertNotNull($found);
        self::assertFalse($found->isRestorable());
        self::assertStringContainsString('misma plataforma', implode(' ', $found->problems));
        self::assertInstanceOf(BackupManifest::class, $found->manifest);
    }

    public function testDeletingRemovesTheFile(): void
    {
        $stored = $this->library->save();

        self::assertTrue($this->library->delete($stored->name));
        self::assertFileDoesNotExist($this->dir . '/' . $stored->name);
        self::assertSame([], $this->library->list());
    }

    public function testItSaysWhetherAUserIsInsideTheCopy(): void
    {
        $this->persist((new Teacher(new PersonName('Ana', 'López')))->setUsername('ana.lopez'));
        $stored = $this->library->save();

        self::assertTrue($this->library->containsUser($stored, 'ana.lopez'));
        self::assertFalse($this->library->containsUser($stored, 'otra.persona'));
    }

    public function testAnEmptyOrMissingFolderListsNothing(): void
    {
        self::assertSame([], $this->library->list());
    }
}
