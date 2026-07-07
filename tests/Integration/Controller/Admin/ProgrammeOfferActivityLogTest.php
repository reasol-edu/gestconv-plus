<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProgrammeOfferActivityLogTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('APP_LOG=false');
        $_ENV['APP_LOG']    = 'false';
        $_SERVER['APP_LOG'] = 'false';
    }

    public function testImportingOfferLogsProgrammeOfferImportedWithSummary(): void
    {
        [$cadmin, $centre, $year] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/offer/import');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $data = [
            'programmes' => [
                [
                    'name'   => 'DAW',
                    'levels' => [
                        [
                            'name'   => '1º',
                            'groups' => [
                                ['name' => '1ºA'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, json_encode($data, JSON_THROW_ON_ERROR));
        $file = new UploadedFile($tmpFile, 'offer.json', 'application/json', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/offer/import', [
            '_token' => $token,
        ], ['json' => $file]);
        @unlink($tmpFile);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('programme_offer.imported', $logs[0]->getActionType());
        self::assertSame(1, $logs[0]->getData()['programmes'] ?? null);
        self::assertSame(1, $logs[0]->getData()['levels'] ?? null);
        self::assertSame(1, $logs[0]->getData()['groups'] ?? null);
        self::assertSame($centreId, $logs[0]->getData()['centreId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('46' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre, $year];
    }
}
