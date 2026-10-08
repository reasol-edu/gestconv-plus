<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppSettingsInterface;
use App\Service\UploadLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UploadLimitsTest extends TestCase
{
    private const MIB = 1024 * 1024;

    public function testDefaultsAreTenMegabytesPerFileAndFiftyPerSubmission(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '64M', 'post_max_size' => '128M']);

        self::assertSame(10 * self::MIB, $limits->maxFileBytes());
        self::assertSame(50 * self::MIB, $limits->maxTotalBytes());
    }

    public function testConfiguredSettingsAreUsed(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '64M', 'post_max_size' => '128M'], ['uploads.max_file_size_mb' => 20, 'uploads.max_total_size_mb' => 80]);

        self::assertSame(20 * self::MIB, $limits->maxFileBytes());
        self::assertSame(80 * self::MIB, $limits->maxTotalBytes());
    }

    public function testPhpLimitsCapTheConfiguredOnes(): void
    {
        // Valores por defecto de PHP (también los del binario sin php.ini): 2M por fichero, 8M por envío.
        $limits = $this->limits(['upload_max_filesize' => '2M', 'post_max_size' => '8M'], ['uploads.max_file_size_mb' => 10, 'uploads.max_total_size_mb' => 50]);

        self::assertSame(2 * self::MIB, $limits->maxFileBytes());
        self::assertLessThan(8 * self::MIB, $limits->maxTotalBytes());
        self::assertGreaterThan(7 * self::MIB, $limits->maxTotalBytes());
    }

    public function testTotalIsNeverSmallerThanTheFileLimit(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '64M', 'post_max_size' => '128M'], ['uploads.max_file_size_mb' => 30, 'uploads.max_total_size_mb' => 5]);

        self::assertSame(30 * self::MIB, $limits->maxTotalBytes());
    }

    public function testUnlimitedPhpDirectivesDoNotCapAnything(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '0', 'post_max_size' => '-1', 'max_file_uploads' => '0']);

        self::assertSame(10 * self::MIB, $limits->maxFileBytes());
        self::assertFalse($limits->isRequestTooLarge($this->requestWithLength(9_999_999_999)));
        self::assertGreaterThan(100, $limits->maxFiles());
    }

    public function testMaxFilesLeavesRoomForPhpSilentlyDroppingExtraFiles(): void
    {
        self::assertSame(19, $this->limits(['max_file_uploads' => '20'])->maxFiles());
        self::assertSame(1, $this->limits(['max_file_uploads' => '1'])->maxFiles());
    }

    public function testRequestLargerThanPostMaxSizeIsDetectedByContentLength(): void
    {
        $limits = $this->limits(['post_max_size' => '8M']);

        self::assertTrue($limits->isRequestTooLarge($this->requestWithLength(9 * self::MIB)));
        self::assertFalse($limits->isRequestTooLarge($this->requestWithLength(7 * self::MIB)));
        self::assertFalse($limits->isRequestTooLarge(new Request()), 'Sin Content-Length no se puede saber');
    }

    public function testFilesRejectedByPhpAreReportedInsteadOfIgnored(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '2M', 'post_max_size' => '8M']);

        $errors = $limits->validate([
            $this->upload('grande.pdf', 1, \UPLOAD_ERR_INI_SIZE),
            $this->upload('cortado.pdf', 1, \UPLOAD_ERR_PARTIAL),
            $this->upload('sin-disco.pdf', 1, \UPLOAD_ERR_CANT_WRITE),
        ]);

        self::assertCount(3, $errors);
        self::assertStringContainsString('upload.error.server_limit', $errors[0]);
        self::assertStringContainsString('grande.pdf', $errors[0]);
        self::assertStringContainsString('upload.error.partial', $errors[1]);
        self::assertStringContainsString('upload.error.not_saved', $errors[2]);
    }

    public function testFileOverTheLimitIsReportedWithTheEffectiveMaximum(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '64M', 'post_max_size' => '128M'], ['uploads.max_file_size_mb' => 1]);

        $errors = $limits->validate([$this->upload('doc.pdf', 2 * self::MIB), $this->upload('ok.pdf', 100)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('attachment.error.too_large', $errors[0]);
        self::assertStringContainsString('doc.pdf', $errors[0]);
        self::assertStringContainsString('1 MB', $errors[0]);
    }

    public function testTotalOverTheLimitIsReported(): void
    {
        $limits = $this->limits(['upload_max_filesize' => '64M', 'post_max_size' => '128M'], ['uploads.max_file_size_mb' => 1, 'uploads.max_total_size_mb' => 1]);

        $errors = $limits->validate([$this->upload('a.pdf', 700 * 1024), $this->upload('b.pdf', 700 * 1024)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('upload.error.total_too_large', $errors[0]);
    }

    public function testTooManyFilesAreReported(): void
    {
        $limits = $this->limits(['max_file_uploads' => '3']);

        $errors = $limits->validate([$this->upload('1.pdf', 10), $this->upload('2.pdf', 10), $this->upload('3.pdf', 10)]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('upload.error.too_many_files', $errors[0]);
    }

    public function testEmptyFileInputsAreNotAnError(): void
    {
        $limits = $this->limits([]);

        self::assertSame([], $limits->validate([null, $this->upload('', 0, \UPLOAD_ERR_NO_FILE)]));
        self::assertNull($limits->fileProblem(null));
        self::assertNull($limits->fileProblem($this->upload('', 0, \UPLOAD_ERR_NO_FILE)));
    }

    public function testSizesAreFormatted(): void
    {
        $limits = $this->limits([]);

        self::assertSame('10 MB', $limits->formatSize(10 * self::MIB));
        self::assertSame('1,5 MB', $limits->formatSize((int) (1.5 * self::MIB)));
        self::assertSame('300 KB', $limits->formatSize(300 * 1024));
    }

    /**
     * @param array<string, string>   $ini      directivas de PHP simuladas
     * @param array<string, int|null> $settings ajustes globales de la aplicación
     */
    private function limits(array $ini, array $settings = []): UploadLimits
    {
        $appSettings = $this->createStub(AppSettingsInterface::class);
        $appSettings->method('getGlobal')->willReturnCallback(static fn (string $key): mixed => $settings[$key] ?? null);

        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id . ' ' . implode(' ', array_map('strval', $parameters));
            }

            public function getLocale(): string
            {
                return 'es';
            }
        };

        return new class($appSettings, $translator, $ini) extends UploadLimits {
            /** @param array<string, string> $ini */
            public function __construct(AppSettingsInterface $settings, TranslatorInterface $translator, private readonly array $ini)
            {
                parent::__construct($settings, $translator);
            }

            protected function iniValue(string $directive): string
            {
                return $this->ini[$directive] ?? '';
            }
        };
    }

    private function requestWithLength(int $bytes): Request
    {
        $request = new Request();
        $request->headers->set('Content-Length', (string) $bytes);

        return $request;
    }

    private function upload(string $name, int $size, int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ul');
        file_put_contents($path, str_repeat('a', $size));

        return new UploadedFile($path, $name, 'application/pdf', $error, true);
    }
}
