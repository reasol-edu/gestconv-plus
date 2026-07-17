<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\FileSizeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileSizeExtensionTest extends TestCase
{
    private FileSizeExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new FileSizeExtension();
    }

    #[DataProvider('provideSizes')]
    public function testFormatsFileSize(int $bytes, string $expected): void
    {
        self::assertSame($expected, $this->extension->formatFileSize($bytes));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideSizes(): iterable
    {
        yield 'zero bytes' => [0, '0 B'];
        yield 'small byte count' => [512, '512 B'];
        yield 'exactly one kibibyte' => [1024, '1,0 KiB'];
        yield 'kibibytes with decimal' => [1536, '1,5 KiB'];
        yield 'mebibytes' => [5 * 1024 * 1024, '5,0 MiB'];
        yield 'mebibytes with decimal' => [(int) (2.5 * 1024 * 1024), '2,5 MiB'];
        yield 'gibibytes' => [2 * 1024 * 1024 * 1024, '2,0 GiB'];
    }
}
