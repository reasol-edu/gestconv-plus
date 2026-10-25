<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Group;
use App\Service\GroupColorPalette;
use PHPUnit\Framework\TestCase;

class GroupColorPaletteTest extends TestCase
{
    public function testSameGroupIdAlwaysGetsTheSameColor(): void
    {
        $palette = new GroupColorPalette();
        $groupId = '11111111-1111-1111-1111-111111111111';

        self::assertSame($palette->colorFor($groupId), $palette->colorFor($groupId));
        self::assertSame($palette->defaultHex($groupId), $palette->defaultHex($groupId));
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

    public function testColorHasHexBackgroundTextAndBorderAndAReadyToUseStyle(): void
    {
        $color = (new GroupColorPalette())->colorFor('some-group-id');

        foreach (['hex', 'bg', 'text', 'border'] as $key) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color[$key], $key);
        }
        self::assertSame(
            sprintf('background-color:%s;color:%s;border-color:%s', $color['bg'], $color['text'], $color['border']),
            $color['style'],
        );
    }

    public function testACustomColorOverridesTheDefaultOne(): void
    {
        $palette = new GroupColorPalette();

        $color = $palette->colorFor('some-group-id', '#123456');

        self::assertSame('#123456', $color['hex']);
        self::assertNotSame($palette->colorFor('some-group-id')['hex'], $color['hex']);
    }

    public function testTheBackgroundIsLighterAndTheTextDarkerThanTheBaseColor(): void
    {
        $color = (new GroupColorPalette())->fromHex('#3b82f6');

        self::assertSame('#e0ebfe', $color['bg']);
        self::assertSame('#1b3b6f', $color['text']);
    }

    public function testAGroupUsesItsCustomColorAndFallsBackToTheComputedOne(): void
    {
        $palette = new GroupColorPalette();
        $group   = (new Group())->setName('1ºA');
        $id      = new \ReflectionProperty(Group::class, 'id');
        $id->setValue($group, \Symfony\Component\Uid\Uuid::fromString('11111111-1111-1111-1111-111111111111'));

        self::assertSame($palette->defaultHex('11111111-1111-1111-1111-111111111111'), $palette->hexFor($group));

        $group->setColor('#AB12CD');
        self::assertSame('#ab12cd', $palette->hexFor($group), 'se guarda en minúsculas');

        $group->setColor(null);
        self::assertSame($palette->defaultHex('11111111-1111-1111-1111-111111111111'), $palette->hexFor($group), 'quitar el color vuelve al calculado');
    }

    public function testAnInvalidColorIsDiscarded(): void
    {
        self::assertNull((new Group())->setColor('rojo')->getColor());
        self::assertNull((new Group())->setColor('#12345')->getColor());
        self::assertNull((new Group())->setColor('javascript:alert(1)')->getColor());
    }
}
