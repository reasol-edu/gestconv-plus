<?php

declare(strict_types=1);

namespace App\Tests\Unit\Composer;

use App\Composer\ProductionAssets;
use PHPUnit\Framework\TestCase;

/** Compilación automática de los assets tras `composer install --no-dev`. */
class ProductionAssetsTest extends TestCase
{
    public function testItBuildsTheCssFirstAndThenTheAssets(): void
    {
        self::assertSame([['tailwind:build', '--minify'], ['asset-map:compile']], ProductionAssets::commands());
    }

    public function testItDoesNothingInDevMode(): void
    {
        $dev = new class {
            public function isDevMode(): bool
            {
                return true;
            }
        };
        $assets = dirname(__DIR__, 3) . '/public/assets';
        $existed = is_dir($assets);

        ob_start();
        ProductionAssets::build($dev);
        $output = (string) ob_get_clean();

        self::assertSame('', $output, 'Con `composer install` normal no se compila nada (congelaría AssetMapper en desarrollo).');
        self::assertSame($existed, is_dir($assets), 'No se crea public/assets.');
    }

    public function testItCanBeSkippedWithAnEnvironmentVariable(): void
    {
        $production = new class {
            public function isDevMode(): bool
            {
                return false;
            }
        };
        putenv('GESTCONV_SKIP_ASSET_BUILD=1');
        try {
            ob_start();
            ProductionAssets::build($production);
            self::assertSame('', (string) ob_get_clean());
        } finally {
            putenv('GESTCONV_SKIP_ASSET_BUILD');
        }
    }

    public function testComposerRunsItAfterEveryInstallAndUpdate(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (['post-install-cmd', 'post-update-cmd'] as $event) {
            self::assertContains('App\\Composer\\ProductionAssets::build', $composer['scripts'][$event], "Falta el hook en $event.");
            self::assertSame('@auto-scripts', $composer['scripts'][$event][0], 'Va después de auto-scripts (que instala el importmap).');
        }
    }

    public function testTheDockerBuildAndTheBinariesSkipComposerScripts(): void
    {
        // Compilan por su cuenta y, si ejecutaran los scripts, compilarían dos veces.
        $root = dirname(__DIR__, 3);
        self::assertStringContainsString('--no-scripts', (string) file_get_contents($root . '/Dockerfile'));
        self::assertStringContainsString('--no-scripts', (string) file_get_contents($root . '/.github/workflows/build.yml'));
    }
}
