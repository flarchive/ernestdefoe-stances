<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// A block belongs to its post. Deleting a discussion takes its posts (and through post_id, their
// blocks) with it; a discussion deleted after fof/merge-discussions moved its posts away must not
// take those posts' blocks too. So discussion_id is only a pointer, set to null on delete and
// brought back in line by Listener\FollowPosts.
return [
    'up' => function (Builder $schema) {
        $schema->table('stance_blocks', function (Blueprint $table) {
            $table->dropForeign(['discussion_id']);
        });
        $schema->table('stance_blocks', function (Blueprint $table) {
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('stance_blocks', function (Blueprint $table) {
            $table->dropForeign(['discussion_id']);
        });
        $schema->table('stance_blocks', function (Blueprint $table) {
            $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
        });
    },
];
