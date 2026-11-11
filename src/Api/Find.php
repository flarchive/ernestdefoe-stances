<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Settings;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

final class Find
{
    /**
     * The block in the route if the actor may see it: one showing in a post they can see, their own
     * draft, or (for whoever may edit the post) one hidden from its post. A 404 otherwise, the same
     * as one that doesn't exist.
     */
    public static function visible(ServerRequestInterface $request, User $actor): Block
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $block = Block::query()->whereVisibleTo($actor)->find($id);

        if (! $block && ! $actor->isGuest()) {
            $block = Block::query()->find($id);
            if ($block && ! self::isOwnDraft($block, $actor) && ! self::mayEditPost($block, $actor)) {
                $block = null;
            }
        }

        if (! $block) {
            throw new Refusal('stances_not_found', 404);
        }

        return $block;
    }

    public static function isOwnDraft(Block $block, User $actor): bool
    {
        return $block->post_id === null && (int) $block->user_id === (int) $actor->id;
    }

    /** Hidden blocks are listed for whoever may edit their post, so the composer can show them. */
    public static function mayEditPost(Block $block, User $actor): bool
    {
        if (! $block->post_id) {
            return false;
        }
        $post = Post::whereVisibleTo($actor)->find($block->post_id);

        return $post !== null && ($actor->can('edit', $post) || Access::can($actor, Settings::MODERATE, $post->discussion));
    }
}
