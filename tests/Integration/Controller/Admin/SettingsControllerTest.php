<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Service\Feature;
use App\Tests\Integration\ControllerTestCase;

class SettingsControllerTest extends ControllerTestCase
{
    public function testSettingsRedirectsAnonymousUser(): void
    {
        $this->client->request('GET', '/admin/ajustes');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSettingsDeniesNonAdminTeacher(): void
    {
        $teacher = $this->makeTeacher('teacher.settings');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/admin/ajustes');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsShowsTheFeatureTogglesToAGlobalAdmin(): void
    {
        foreach (Feature::cases() as $feature) {
            $this->persist((new SettingDefinition())->setKey($feature->setting())->setType(SettingType::Boolean)
                ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true)
                ->setCategory('settings.category.features')->setCategoryOrder(6));
        }
        $admin = $this->makeAdmin('admin.features');
        $this->persist($admin);
        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/ajustes');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Secciones de la aplicación', $crawler->text());
        self::assertStringContainsString('Salidas del aula', $crawler->text());
        foreach (Feature::cases() as $feature) {
            self::assertCount(1, $crawler->filter(sprintf('select[data-setting-key="%s"]', $feature->setting())));
        }
    }

    public function testSettingsIsAccessibleToAdmin(): void
    {
        $admin = $this->makeAdmin('admin.settings');
        $this->persist($admin);
        $this->loginAs($admin);

        $this->client->request('GET', '/admin/ajustes');

        self::assertResponseIsSuccessful();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeAdmin(string $username): Teacher
    {
        return (new Teacher(new PersonName('Admin', 'User')))->setUsername($username)->setAdmin(true);
    }
}
