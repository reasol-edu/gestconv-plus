<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\SettingFile;
use App\Repository\CentreSettingValueRepository;
use App\Repository\GlobalSettingValueRepository;
use App\Repository\TeacherSettingValueRepository;
use App\Service\SettingFileGarbageCollector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SettingFileGarbageCollectorTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private GlobalSettingValueRepository&Stub $globalValues;
    private CentreSettingValueRepository&Stub $centreValues;
    private TeacherSettingValueRepository&Stub $teacherValues;
    private SettingFileGarbageCollector $collector;
    private SettingFile $file;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->globalValues  = $this->createStub(GlobalSettingValueRepository::class);
        $this->centreValues  = $this->createStub(CentreSettingValueRepository::class);
        $this->teacherValues = $this->createStub(TeacherSettingValueRepository::class);

        $this->collector = new SettingFileGarbageCollector(
            $this->globalValues,
            $this->centreValues,
            $this->teacherValues,
            $this->em,
        );

        $this->file = new SettingFile('abc123', 'contenido', 'application/pdf', 9);
    }

    public function testDoesNotDeleteWhenStillReferenced(): void
    {
        $this->globalValues->method('countByFile')->willReturn(0);
        $this->centreValues->method('countByFile')->willReturn(1);
        $this->teacherValues->method('countByFile')->willReturn(0);

        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::never())->method('flush');

        $this->collector->deleteIfOrphaned($this->file);
    }

    public function testDeletesWhenNoLongerReferenced(): void
    {
        $this->globalValues->method('countByFile')->willReturn(0);
        $this->centreValues->method('countByFile')->willReturn(0);
        $this->teacherValues->method('countByFile')->willReturn(0);

        $this->em->expects(self::once())->method('remove')->with($this->file);
        $this->em->expects(self::once())->method('flush');

        $this->collector->deleteIfOrphaned($this->file);
    }
}
