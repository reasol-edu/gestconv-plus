<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EntityChangeTracker;
use PHPUnit\Framework\TestCase;

class EntityChangeTrackerTest extends TestCase
{
    public function testSnapshotReadsGetterValues(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'Ana';
            }

            public function isActive(): bool
            {
                return true;
            }
        };

        $tracker = new EntityChangeTracker();

        self::assertSame(
            ['name' => 'Ana', 'active' => true],
            $tracker->snapshot($entity, ['name', 'active']),
        );
    }

    public function testDiffReturnsOnlyChangedFields(): void
    {
        $entity = new class {
            public string $name = 'Ana';
            public string $email = 'ana@example.com';

            public function getName(): string
            {
                return $this->name;
            }

            public function getEmail(): string
            {
                return $this->email;
            }
        };

        $tracker = new EntityChangeTracker();
        $before  = $tracker->snapshot($entity, ['name', 'email']);

        $entity->name = 'Ana María';

        self::assertSame(
            ['name' => ['before' => 'Ana', 'after' => 'Ana María']],
            $tracker->diff($before, $entity, ['name', 'email']),
        );
    }

    public function testDiffReturnsEmptyArrayWhenNothingChanged(): void
    {
        $entity = new class {
            public function getName(): string
            {
                return 'Ana';
            }
        };

        $tracker = new EntityChangeTracker();
        $before  = $tracker->snapshot($entity, ['name']);

        self::assertSame([], $tracker->diff($before, $entity, ['name']));
    }

    public function testSnapshotFallsBackToRawMethodNameWhenNoGetterOrIsserExists(): void
    {
        $entity = new class {
            public function hasDateRange(): bool
            {
                return true;
            }
        };

        $tracker = new EntityChangeTracker();

        self::assertSame(
            ['hasDateRange' => true],
            $tracker->snapshot($entity, ['hasDateRange']),
        );
    }

    public function testNormalizesDateTimeAndBackedEnum(): void
    {
        $date = new \DateTimeImmutable('2026-01-15T10:00:00+00:00');

        $entity = new class($date) {
            public function __construct(private readonly \DateTimeImmutable $date)
            {
            }

            public function getDate(): \DateTimeImmutable
            {
                return $this->date;
            }

            public function getStatus(): EntityChangeTrackerTestStatus
            {
                return EntityChangeTrackerTestStatus::Active;
            }
        };

        $tracker = new EntityChangeTracker();

        self::assertSame(
            [
                'date'   => $date->format(DATE_ATOM),
                'status' => 'active',
            ],
            $tracker->snapshot($entity, ['date', 'status']),
        );
    }
}

enum EntityChangeTrackerTestStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
