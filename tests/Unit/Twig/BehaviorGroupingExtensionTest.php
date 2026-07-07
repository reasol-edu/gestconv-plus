<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Twig\BehaviorGroupingExtension;
use PHPUnit\Framework\TestCase;

class BehaviorGroupingExtensionTest extends TestCase
{
    private BehaviorGroupingExtension $extension;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->extension = new BehaviorGroupingExtension();
        $this->centre    = new EducationalCentre();
    }

    public function testGroupsBehaviorsUnderTheirCategory(): void
    {
        $disruptive = $this->makeCategory('Disruptivas', false, 10);
        $respect    = $this->makeCategory('Faltas de respeto', false, 20);

        $shouting    = $this->makeBehavior('Gritar en clase', $disruptive, 10);
        $interrupt   = $this->makeBehavior('Interrumpir la clase', $disruptive, 20);
        $insult      = $this->makeBehavior('Insultar a un compañero', $respect, 10);

        $groups = $this->extension->groupByCategory([$shouting, $interrupt, $insult]);

        self::assertCount(2, $groups);
        self::assertSame($disruptive, $groups[0]['category']);
        self::assertSame([$shouting, $interrupt], $groups[0]['behaviors']);
        self::assertSame($respect, $groups[1]['category']);
        self::assertSame([$insult], $groups[1]['behaviors']);
    }

    public function testOrdersBehaviorsWithinACategoryByPosition(): void
    {
        $category = $this->makeCategory('Disruptivas', false, 10);
        $second   = $this->makeBehavior('Segunda', $category, 20);
        $first    = $this->makeBehavior('Primera', $category, 10);

        // Passed out of order on purpose: the filter must sort by position, not input order.
        $groups = $this->extension->groupByCategory([$second, $first]);

        self::assertSame([$first, $second], $groups[0]['behaviors']);
    }

    public function testOrdersCategoriesByPosition(): void
    {
        $later    = $this->makeCategory('Categoría B', false, 20);
        $earlier  = $this->makeCategory('Categoría A', false, 10);
        $laterBehavior   = $this->makeBehavior('X', $later, 0);
        $earlierBehavior = $this->makeBehavior('Y', $earlier, 0);

        // Passed out of order on purpose: the filter must sort by category position, not input order.
        $groups = $this->extension->groupByCategory([$laterBehavior, $earlierBehavior]);

        self::assertSame($earlier, $groups[0]['category']);
        self::assertSame($later, $groups[1]['category']);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->extension->groupByCategory([]));
    }

    private function makeCategory(string $name, bool $serious, int $position): IncidentBehaviorCategory
    {
        return (new IncidentBehaviorCategory())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setSerious($serious)
            ->setPosition($position);
    }

    private function makeBehavior(string $name, IncidentBehaviorCategory $category, int $position): IncidentBehavior
    {
        return (new IncidentBehavior())
            ->setEducationalCentre($this->centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position);
    }
}
