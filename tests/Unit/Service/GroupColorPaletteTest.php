<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GroupColorPalette;
use PHPUnit\Framework\TestCase;

class GroupColorPaletteTest extends TestCase
{
    public function testSameGroupIdAlwaysGetsTheSameColor(): void
    {
        $palette = new GroupColorPalette();
        $groupId = '11111111-1111-1111-1111-111111111111';

        $first  = $palette->colorFor($groupId);
        $second = $palette->colorFor($groupId);

        self::assertSame($first, $second);
    }

    public function testDifferentGroupIdsCanGetDifferentColors(): void
    {
        $palette = new GroupColorPalette();

        $colors = [];
        for ($i = 0; $i < 12; $i++) {
            $colors[] = $palette->colorFor((string) $i);
        }

        self::assertGreaterThan(1, count(array_unique(array_column($colors, 'bg'))));
    }

    public function testColorHasBackgroundTextAndBorderClasses(): void
    {
        $palette = new GroupColorPalette();

        $color = $palette->colorFor('some-group-id');

        self::assertArrayHasKey('bg', $color);
        self::assertArrayHasKey('text', $color);
        self::assertArrayHasKey('border', $color);
        self::assertStringStartsWith('bg-', $color['bg']);
        self::assertStringStartsWith('text-', $color['text']);
        self::assertStringStartsWith('border-', $color['border']);
    }
}
