<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Update;

use App\Entity\GlobalSettingValue;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Service\Update\LatestReleaseChecker;
use App\Service\Update\UpdateStatus;
use App\Tests\Integration\RepositoryTestCase;
use App\Tests\Support\ReleaseHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;

/** Aviso de versión nueva: consulta a GitHub, comparación, caché y ajuste que la desactiva. */
class LatestReleaseCheckerTest extends RepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReleaseHttpClient::clear();
    }

    private function checker(): LatestReleaseChecker
    {
        /** @var LatestReleaseChecker $checker */
        $checker = self::getContainer()->get(LatestReleaseChecker::class);

        return $checker;
    }

    public function testANewerReleaseIsReported(): void
    {
        ReleaseHttpClient::willPublish('v99.0.0');

        $status = $this->checker()->status();

        self::assertTrue($status->isNewer());
        self::assertSame('99.0.0', $status->version);
        // La dirección se construye con el repositorio del proyecto: la de la respuesta (de otro dominio) se ignora.
        self::assertSame('https://github.com/reasol-edu/gestconv-plus/releases/tag/v99.0.0', $status->url);
        self::assertSame('https://api.github.com/repos/reasol-edu/gestconv-plus/releases/latest', ReleaseHttpClient::$lastUrl);
        self::assertStringContainsString('User-Agent: GestConv+/', implode("\n", ReleaseHttpClient::$lastHeaders), 'GitHub exige un User-Agent.');
    }

    public function testTheInstalledVersionIsNotNewerThanItself(): void
    {
        $installed = self::getContainer()->getParameter('app.version');
        self::assertIsString($installed);
        ReleaseHttpClient::willPublish('v' . $installed);

        $status = $this->checker()->status();

        self::assertSame(UpdateStatus::CURRENT, $status->state);
        self::assertFalse($status->isNewer());
    }

    public function testAnOlderReleaseShowsNothing(): void
    {
        ReleaseHttpClient::willPublish('v0.0.1');

        self::assertSame(UpdateStatus::CURRENT, $this->checker()->status()->state);
    }

    public function testAFailedRequestShowsNothingAndIsCached(): void
    {
        ReleaseHttpClient::willFail();
        $checker = $this->checker();

        self::assertSame(UpdateStatus::UNKNOWN, $checker->status()->state);
        self::assertSame(UpdateStatus::UNKNOWN, $checker->status()->state);
        self::assertSame(UpdateStatus::UNKNOWN, $checker->status()->state);

        self::assertSame(1, ReleaseHttpClient::$requests, 'Un servidor sin internet no debe esperar en cada carga: el fallo también se cachea.');
    }

    public function testASuccessIsCachedToo(): void
    {
        ReleaseHttpClient::willPublish('v99.0.0');
        $checker = $this->checker();

        $checker->status();
        $checker->status();

        self::assertSame(1, ReleaseHttpClient::$requests);
    }

    public function testAnErrorStatusIsUnknown(): void
    {
        ReleaseHttpClient::willReturnStatus(403, '{"message":"rate limit exceeded"}');

        self::assertSame(UpdateStatus::UNKNOWN, $this->checker()->status()->state);
    }

    /** @return iterable<string, array{string}> */
    public static function badTags(): iterable
    {
        yield 'sin versión' => ['latest'];
        yield 'con texto' => ['v1.2.3<script>'];
        yield 'incompleta' => ['v1.2'];
    }

    #[DataProvider('badTags')]
    public function testAnUnexpectedTagIsIgnored(string $tag): void
    {
        ReleaseHttpClient::willPublish($tag);

        self::assertSame(UpdateStatus::UNKNOWN, $this->checker()->status()->state);
    }

    public function testTheSettingDisablesEveryRequest(): void
    {
        $definition = (new SettingDefinition())
            ->setKey(LatestReleaseChecker::SETTING)->setType(SettingType::Boolean)->setDefaultValue('true')
            ->setGlobalScope(true)->setCentreScope(false)->setTeacherScope(false)
            ->setCategory('settings.category.maintenance')->setCategoryOrder(5)->setPosition(20);
        $this->persist($definition);
        $this->persist((new GlobalSettingValue())->setDefinition($definition)->setValue('false'));

        ReleaseHttpClient::willPublish('v99.0.0');
        $status = $this->checker()->status();

        self::assertSame(UpdateStatus::DISABLED, $status->state);
        self::assertSame(0, ReleaseHttpClient::$requests, 'Con el ajuste desactivado no sale ninguna petición.');
    }
}
