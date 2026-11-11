<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Block;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `stances` JSON:API type, with no endpoints of its own: the forum reads blocks through the
 * plain-JSON controllers. It exists because a notification's subject must have a registered
 * resource, or every notification list 500s.
 *
 * @extends AbstractDatabaseResource<Block>
 */
class StanceResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'stances';
    }

    public function model(): string
    {
        return Block::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        /** @var Builder<Block> $query */
        $query->whereVisibleTo(RequestUtil::getActor($context->request));
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('question'),
            Schema\Integer::make('discussionId')->property('discussion_id'),
            Schema\Integer::make('postId')->property('post_id'),
        ];
    }
}
