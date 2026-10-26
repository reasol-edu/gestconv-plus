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

    private function signInAsAdmin(): void
    {
        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $admin        = (new Teacher(new PersonName('Nombre', 'Admin')))->setUsername('admin')->setAdmin(true);
        $this->persist($this->centre, $admin);
        $this->loginAs($admin, $this->centre);
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
        yield 'actividades'       => [Feature::Events, '/actividades/nuevo'];
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

    /** @return iterable<string, array{Feature, string}> */
    public static function catalogs(): iterable
    {
        yield 'motivos de salida' => [Feature::Exits, 'motivos-salida'];
        yield 'tipos de nota'     => [Feature::DailyNotes, 'tipos-notas'];
    }

    #[DataProvider('catalogs')]
    public function testACatalogIsOpenWhileItsSectionIsEnabled(Feature $feature, string $path): void
    {
        $this->signInAsAdmin();
        $this->defineFeatures();

        $this->client->request('GET', sprintf('/centro/%s/%s', $this->centre->getId()->toRfc4122(), $path));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    #[DataProvider('catalogs')]
    public function testACatalogOfADisabledSectionAnswersNotFound(Feature $feature, string $path): void
    {
        $this->signInAsAdmin();
        $this->defineFeatures();
        $this->disableForCentre($feature);

        $this->client->request('GET', sprintf('/centro/%s/%s', $this->centre->getId()->toRfc4122(), $path));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testACatalogIsJudgedByTheCentreInTheUrlNotTheSelectedOne(): void
    {
        $this->signInAsAdmin();
        $this->defineFeatures();
        $other = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Ciudad');
        $this->persist($other);
        $this->persist((new CentreSettingValue())->setDefinition($this->definitions['exits'])->setCentre($other)->setValue('false'));

        $this->client->request('GET', sprintf('/centro/%s/motivos-salida', $other->getId()->toRfc4122()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'el centro seleccionado lo tiene activado, pero el de la dirección no');
    }

    public function testLiveComponentUpdatesOfADisabledSectionAreBlocked(): void
    {
        $this->signIn();
        $this->defineFeatures(global: false);

        $this->client->request('POST', '/_components/ExitCurrentListComponent/render');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
