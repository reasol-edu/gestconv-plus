<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class BehaviorGroupingExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('group_by_category', $this->groupByCategory(...)),
        ];
    }

    /**
     * @param iterable<IncidentBehavior> $behaviors
     *
     * @return list<array{category: IncidentBehaviorCategory, behaviors: list<IncidentBehavior>}>
     */
    public function groupByCategory(iterable $behaviors): array
    {
        $groups = [];
        foreach ($behaviors as $behavior) {
            $category = $behavior->getCategory();
            $key      = spl_object_id($category);

            $groups[$key]              ??= ['category' => $category, 'behaviors' => []];
            $groups[$key]['behaviors'][] = $behavior;
        }

        foreach ($groups as &$group) {
            usort($group['behaviors'], static fn (IncidentBehavior $a, IncidentBehavior $b) => $a->getPosition() <=> $b->getPosition());
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b) => $a['category']->getPosition() <=> $b['category']->getPosition());

        return $groups;
    }
}
