<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Service\Feature;
use App\Tests\Integration\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FeatureToggleSubscriberTest extends ControllerTestCase
{
    private EducationalCentre $centre;

    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    private function signIn(): void
    {
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $teacher      = (new Teacher(new PersonName('Nombre', 'Docente')))->setUsername('docente');
        $this->persist($this->centre, $teacher);
        $this->loginAs($teacher, $this->centre);
    }

    /** Define los cuatro ajustes (como hace la migración) con el valor global indicado. */
    private function defineFeatures(bool $global = true): void
    {
        foreach (Feature::cases() as $feature) {
            $definition = (new SettingDefinition())->setKey($feature->setting())->setType(SettingType::Boolean)
                ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
            $this->definitions[$feature->value] = $definition;
            $this->persist($definition);
            if (!$global) {
                $this->persist((new GlobalSettingValue())->setDefinition($definition)->setValue('false'));
            }
        }
    }

    private function disableForCentre(Feature $feature): void
    {
        $this->persist((new CentreSettingValue())->setDefinition($this->definitions[$feature->value])->setCentre($this->centre)->setValue('false'));
    }

    /** @return iterable<string, array{Feature, string}> */
    public static function sections(): iterable
    {
        yield 'salidas'           => [Feature::Exits, '/salidas'];
        yield 'ausencias'         => [Feature::Absences, '/ausencias'];
        yield 'notas'             => [Feature::DailyNotes, '/notas'];
        yield 'tareas de sanción' => [Feature::SanctionTasks, '/tareas-de-sancion'];
    }

    #[DataProvider('sections')]
    public function testSectionsAreEnabledByDefault(Feature $feature, string $url): void
    {
        $this->signIn();
        $this->defineFeatures();

        $this->client->request('GET', $url);

        self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
    }

    #[DataProvider('sections')]
    public function testSectionsAreEnabledWhenTheSettingDoesNotExist(Feature $feature, string $url): void
    {
        $this->signIn();

        $this->client->request('GET', $url);

        self::assertNotSame(404, $this->client->getResponse()->getStatusCode(), 'una base de datos sin los ajustes no debe cerrar ninguna sección');
    }

    #[DataProvider('sections')]
    public function testASectionDisabledGloballyAnswersNotFound(Feature $feature, string $url): void
    {
        $this->signIn();
        $this->defineFeatures(global: false);

        $this->client->request('GET', $url);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    #[DataProvider('sections')]
    public function testASectionDisabledForTheCentreAnswersNotFound(Feature $feature, string $url): void
    {
        $this->signIn();
        $this->defineFeatures();
        $this->disableForCentre($feature);

        $this->client->request('GET', $url);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDisablingOneSectionLeavesTheOthersOpen(): void
    {
        $this->signIn();
        $this->defineFeatures();
        $this->disableForCentre(Feature::Exits);

        $this->client->request('GET', '/notas');

        self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTheMenuShowsEverySectionByDefault(): void
    {
        $this->signIn();
        $this->defineFeatures();

        $crawler = $this->client->request('GET', '/perfil');

        self::assertCount(1, $crawler->filter('nav a[href="/salidas"]'));
        self::assertCount(1, $crawler->filter('nav a[href="/notas"]'));
    }

    public function testTheMenuDropsADisabledSection(): void
    {
        $this->signIn();
        $this->defineFeatures();
        $this->disableForCentre(Feature::Exits);

        $crawler = $this->client->request('GET', '/perfil');

        self::assertCount(0, $crawler->filter('nav a[href="/salidas"]'));
        self::assertCount(1, $crawler->filter('nav a[href="/notas"]'), 'las demás secciones siguen en el menú');
    }

    public function testLiveComponentUpdatesOfADisabledSectionAreBlocked(): void
    {
        $this->signIn();
        $this->defineFeatures(global: false);

        $this->client->request('POST', '/_components/ExitCurrentListComponent/render');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
