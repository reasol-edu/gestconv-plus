<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Pagination\Paginator;
use Doctrine\ORM\Query;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Shared page-navigation state for *ListComponent Live Components.
 * Requires the using class to have an AppSettings $appSettings property.
 */
trait PaginatedListTrait
{
    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveAction]
    public function setPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
    }

    #[LiveAction]
    public function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @template T of object
     * @param Query<null, T> $query
     * @return Paginator<T>
     */
    private function paginate(Query $query): Paginator
    {
        return new Paginator($query, max(1, $this->page), $this->appSettings->getInt('page.size'));
    }
}
