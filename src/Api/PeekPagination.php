<?php

namespace ErnestDefoe\Stances\Api;

use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use WeakMap;

/**
 * Offset paging that knows whether there's a next page without counting every match: it fetches
 * one row past the page, StanceResource::results() trims it and says whether it was there, and
 * links.next is left out when it wasn't (a full last page would otherwise link to an empty one).
 */
class PeekPagination extends OffsetPagination
{
    public ?bool $more = null;

    /** @var WeakMap<object, self>|null by request */
    private static ?WeakMap $pages = null;

    public function __construct(Context $context, int $defaultLimit = 20, int $maxLimit = 50)
    {
        parent::__construct($context, $defaultLimit, $maxLimit);
        self::$pages ??= new WeakMap();
        self::$pages[$context->request] = $this;
    }

    public static function for(Context $context): ?self
    {
        return self::$pages[$context->request] ?? null;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<\ErnestDefoe\Stances\Block> $query */
    public function apply($query): void
    {
        $query->skip($this->offset)->take($this->limit + 1);
    }

    public function links(int $count, ?int $total): array
    {
        $links = parent::links($count, $total);
        if ($this->more === false) {
            unset($links['next']);
        }

        return $links;
    }
}
