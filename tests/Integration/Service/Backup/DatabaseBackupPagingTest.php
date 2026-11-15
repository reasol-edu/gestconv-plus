<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Entity\SettingDefinition;
use App\Entity\SettingFile;
use App\Entity\SettingType;
use App\Service\Backup\DatabaseBackup;
use App\Tests\Integration\RepositoryTestCase;

/**
 * La copia lee cada tabla por páginas de su clave primaria (pdo_mysql y pdo_pgsql cargan en memoria el resultado completo
 * antes de devolver la primera fila, así que un único `SELECT *` sobre la tabla de ficheros los tendría todos a la vez):
 * cada fila debe salir exactamente una vez, también al cruzar los límites de página y con claves uuid.
 */
final class DatabaseBackupPagingTest extends RepositoryTestCase
{
    public function testATableLargerThanOneBatchIsWrittenCompleteAndWithoutDuplicates(): void
    {
        // Más de dos páginas de 200 filas: hay dos fronteras de página.
        foreach (range(1, 450) as $i) {
            $this->em->persist((new SettingDefinition())->setKey(sprintf('paging.test_%03d', $i))->setType(SettingType::String)->setDefaultValue((string) $i));
        }
        $this->em->flush();

        $keys = array_map(static fn (array $row): string => (string) $row['key'], $this->tableRows('setting_definition'));

        self::assertCount(450, $keys);
        self::assertCount(450, array_unique($keys), 'una fila se ha escrito dos veces');
    }

    public function testFilesAreDumpedOneRowAtATimeAndStayIntact(): void
    {
        $expected = [];
        foreach (range(1, 7) as $i) {
            $content = random_bytes(5000 + $i) . "\0\xff"; // binario, no es UTF-8 válido
            $hash    = hash('sha256', $content);
            $this->em->persist(new SettingFile($hash, $content, 'application/octet-stream', strlen($content)));
            $expected[$hash] = $content;
        }
        $this->em->flush();

        $rows = $this->tableRows('setting_file');

        self::assertCount(7, $rows);
        foreach ($rows as $row) {
            self::assertIsArray($row['content']);
            self::assertSame($expected[$row['hash']] ?? null, base64_decode((string) $row['content']['$b64'], true), 'un fichero ha salido distinto de como se guardó');
            unset($expected[$row['hash']]);
        }
        self::assertSame([], $expected, 'falta un fichero en la copia');
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(string $table): array
    {
        /** @var DatabaseBackup $backup */
        $backup = self::getContainer()->get(DatabaseBackup::class);
        $path   = sys_get_temp_dir() . '/gestconv-paging-' . bin2hex(random_bytes(4)) . '.zip';
        $backup->create($path);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY));
        $ndjson = (string) $zip->getFromName('data/' . $table . '.ndjson');
        $zip->close();
        @unlink($path);

        $rows = [];
        foreach (array_filter(explode("\n", $ndjson)) as $line) {
            /** @var array<string, mixed> $row */
            $row    = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }

        return $rows;
    }
}
