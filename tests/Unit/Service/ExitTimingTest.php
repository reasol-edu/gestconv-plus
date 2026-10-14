<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Entity\PersonName;
use App\Service\ExitTiming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

class ExitTimingTest extends TestCase
{
    private ExitTiming $timing;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-14 10:00:00');

        // Devuelve «clave|valores» para comprobar qué texto y qué cifras se eligen sin depender del idioma.
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id . '|' . implode(',', array_map('strval', $parameters));
            }

            public function getLocale(): string
            {
                return 'es';
            }
        };

        $this->timing = new ExitTiming($this->clock, $translator);
    }

    #[DataProvider('levels')]
    public function testLevelGrowsEveryTenMinutesFromTen(int $seconds, int $expected): void
    {
        self::assertSame($expected, $this->timing->level($seconds));
    }

    /** @return iterable<string, array{int, int}> */
    public static function levels(): iterable
    {
        yield 'recién salido' => [0, 0];
        yield '9:59' => [599, 0];
        yield '10:00' => [600, 1];
        yield '19:59' => [1199, 1];
        yield '20:00' => [1200, 2];
        yield '30:00' => [1800, 3];
        yield 'mucho rato' => [7200, 3];
    }

    public function testAgoChoosesSecondsMinutesOrHours(): void
    {
        self::assertSame('exit.ago.seconds_one|1', $this->timing->ago(1));
        self::assertSame('exit.ago.seconds_other|45', $this->timing->ago(45));
        self::assertSame('exit.ago.minutes_one|1', $this->timing->ago(60));
        self::assertSame('exit.ago.minutes_other|5', $this->timing->ago(5 * 60 + 59));
        self::assertSame('exit.ago.hours|1,05', $this->timing->ago(65 * 60));
    }

    public function testDurationRoundsToTheNearestMinute(): void
    {
        self::assertSame('exit.duration.minutes|12', $this->timing->duration(12 * 60 + 20));
        self::assertSame('exit.duration.minutes|13', $this->timing->duration(12 * 60 + 40));
        self::assertSame('exit.duration.hours|1,05', $this->timing->duration(65 * 60));
    }

    public function testSecondsUsesNowWhileOutAndReturnTimeWhenBack(): void
    {
        $teacher = new Teacher(new PersonName('Test', 'Teacher'));
        $exit    = (new StudentExit())->setExitedAt(new \DateTimeImmutable('2026-10-14 09:50:00'));

        self::assertSame(600, $this->timing->seconds($exit));

        $exit->markReturned($teacher, new \DateTimeImmutable('2026-10-14 09:57:30'));
        $this->clock->sleep(3600);
        self::assertSame(450, $this->timing->seconds($exit));
    }

    public function testSecondsIsNeverNegative(): void
    {
        $exit = (new StudentExit())->setExitedAt(new \DateTimeImmutable('2026-10-14 10:05:00'));

        self::assertSame(0, $this->timing->seconds($exit));
    }

    public function testIsTodayComparesWithTheClockDate(): void
    {
        self::assertTrue($this->timing->isToday(new \DateTimeImmutable('2026-10-14 00:01:00')));
        self::assertFalse($this->timing->isToday(new \DateTimeImmutable('2026-10-13 23:59:00')));
    }

    public function testLabelsExposePlaceholdersForTheBrowser(): void
    {
        $labels = $this->timing->labels();

        self::assertSame('exit.ago.seconds_other|%count%', $labels['secondsOther']);
        self::assertSame('exit.ago.hours|%hours%,%minutes%', $labels['hours']);
    }
}
