<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class SettingsRichTextTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    public function testSaveStoresRichTextValueGlobally(): void
    {
        $admin = $this->makeAdmin();
        $this->loginAs($admin);

        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'global'], $this->client);
        $component->call('save', [
            'key'   => 'reports.incident_header_left',
            'value' => '<p><strong>Parte nº {report_nr}</strong></p>',
        ]);

        $stored = $this->findGlobalValue('reports.incident_header_left');
        self::assertNotNull($stored);
        self::assertSame('<p><strong>Parte nº {report_nr}</strong></p>', $stored->getValue());
    }

    public function testSaveResetsRichTextValueWithDefaultSentinel(): void
    {
        $admin = $this->makeAdmin();
        $def   = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'reports.incident_header_left']);
        $this->persist(
            (new GlobalSettingValue())->setDefinition($def)->setValue('<p>Personalizado</p>'),
        );
        $this->loginAs($admin);

        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'global'], $this->client);
        $component->call('save', ['key' => 'reports.incident_header_left', 'value' => '__default__']);

        self::assertNull($this->findGlobalValue('reports.incident_header_left'));
    }

    public function testSaveRejectsRichTextValueExceedingMaxLength(): void
    {
        $admin = $this->makeAdmin();
        $this->loginAs($admin);

        $component = $this->createLiveComponent('SettingsComponent', ['scope' => 'global'], $this->client);
        $component->call('save', [
            'key'   => 'reports.incident_header_left',
            'value' => '<p>' . str_repeat('a', 5001) . '</p>',
        ]);

        self::assertNull($this->findGlobalValue('reports.incident_header_left'));
    }

    public function testGlobalSettingsPageRendersRichEditorBranch(): void
    {
        $admin = $this->makeAdmin();
        $this->loginAs($admin);

        $this->client->request('GET', '/admin/ajustes');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="setting-richtext-reports_incident_header_left"', $html);
        self::assertStringContainsString('data-controller="rich-editor"', $html);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAdmin(): Teacher
    {
        $admin  = (new Teacher(new PersonName('Admin', 'Richtext')))->setUsername('admin.richtext')->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('41000042')->setName('IES Richtext Test')->setCity('Sevilla');
        $this->persist($admin, $centre);

        return $admin;
    }

    private function findGlobalValue(string $key): ?GlobalSettingValue
    {
        $this->em->clear();
        $def = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);

        return $this->em->getRepository(GlobalSettingValue::class)->findOneBy(['definition' => $def]);
    }
}
