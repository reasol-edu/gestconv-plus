<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\Teacher;
use App\EventSubscriber\IdleSessionSubscriber;
use App\Service\AppSettings;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Cierre de sesión por inactividad: ajuste global y qué cuenta como actividad. */
final class IdleSessionSubscriberTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    private EducationalCentre $centre;
    private Teacher $teacher;

    private function loggedInTeacher(bool $admin = false): void
    {
        $this->centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->teacher = (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername('docente')->setAdmin($admin);
        $this->persist($this->centre, $this->teacher);
        $this->loginAs($this->teacher, $this->centre);
    }

    private function definition(): SettingDefinition
    {
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => IdleSessionSubscriber::SETTING]);
        self::assertInstanceOf(SettingDefinition::class, $definition);

        return $definition;
    }

    private function globalTimeout(int $minutes): void
    {
        $this->persist((new GlobalSettingValue())->setDefinition($this->definition())->setValue((string) $minutes));

        /** @var AppSettings $settings */
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->invalidate();
    }

    private function assertExpiredAt(string $time): void
    {
        self::mockTime('2025-10-10 ' . $time);
        $this->client->request('GET', '/perfil');
        self::assertTrue($this->client->getResponse()->isRedirect('/login?sesion=caducada'), "debería haber caducado a las $time");
    }

    private function assertAliveAt(string $time): void
    {
        self::mockTime('2025-10-10 ' . $time);
        $this->client->request('GET', '/perfil');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), "debería seguir abierta a las $time");
    }

    public function testLogsOutAfterTheDefaultTwoHoursWithoutActivity(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();

        $this->assertExpiredAt('11:01:00');
        $this->client->request('GET', '/perfil');
        self::assertTrue($this->client->getResponse()->isRedirect('/login'), 'la sesión se ha cerrado de verdad, no solo redirigido una vez');
    }

    public function testActivityWithinTheTimeoutKeepsTheSessionAlive(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();

        foreach (['10:30:00', '11:59:00', '13:30:00'] as $time) {
            $this->assertAliveAt($time);
        }
    }

    public function testTheTimeoutFollowsTheGlobalSetting(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();
        $this->globalTimeout(15);

        $this->assertExpiredAt('09:16:00');
    }

    public function testTheSettingIsOnlyAvailableAtGlobalLevel(): void
    {
        $definition = $this->definition();

        self::assertTrue($definition->isGlobalScope());
        self::assertFalse($definition->isCentreScope());
        self::assertFalse($definition->isTeacherScope());
    }

    public function testZeroTurnsItOff(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();
        $this->globalTimeout(0);

        self::mockTime('2025-10-12 09:00:00');
        $this->client->request('GET', '/perfil');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAutomaticRefreshesDoNotCountAsActivity(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();
        $this->globalTimeout(30);

        // Una pantalla que se refresca sola cada pocos minutos, sin que nadie la toque.
        foreach (['09:10:00', '09:20:00', '09:29:00'] as $time) {
            self::mockTime('2025-10-10 ' . $time);
            $this->client->request('GET', '/perfil', server: ['HTTP_X_BACKGROUND_REFRESH' => '1']);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
        }

        // A los 31 minutos de la última acción de la persona, caduca aunque el refresco siguiera pidiendo cosas.
        self::mockTime('2025-10-10 09:31:00');
        $this->client->request('GET', '/perfil', server: ['HTTP_X_BACKGROUND_REFRESH' => '1']);
        self::assertTrue($this->client->getResponse()->isRedirect('/login?sesion=caducada'));
    }

    public function testARealRequestAfterAnAutomaticOneKeepsTheSessionAlive(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher();
        $this->globalTimeout(30);

        $this->assertAliveAt('09:25:00');
        self::mockTime('2025-10-10 09:40:00');
        $this->client->request('GET', '/perfil', server: ['HTTP_X_BACKGROUND_REFRESH' => '1']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'aún dentro de los 30 minutos de la última actividad real');
    }

    public function testKioskModeNeverExpires(): void
    {
        self::mockTime('2025-10-10 09:00:00');
        $this->loggedInTeacher(admin: true);
        $this->globalTimeout(15);
        $session = $this->client->getRequest()->getSession();
        $session->set('kiosk.locked', true);
        $session->save();

        // Cuatro horas después, con un tiempo de 15 minutos: el tablón sigue abierto.
        self::mockTime('2025-10-10 13:00:00');
        $this->client->request('GET', '/calendario/tablon');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheLoginPageExplainsWhy(): void
    {
        $this->client->request('GET', '/login?sesion=caducada');

        self::assertStringContainsString('sin actividad', (string) $this->client->getResponse()->getContent());
    }

    public function testTheLoginPageSaysNothingByDefault(): void
    {
        $this->client->request('GET', '/login');

        self::assertStringNotContainsString('sin actividad', (string) $this->client->getResponse()->getContent());
    }
}
