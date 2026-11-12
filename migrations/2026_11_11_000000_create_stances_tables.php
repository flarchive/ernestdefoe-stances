<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('stance_blocks', function (Blueprint $table) {
            $table->increments('id');
            // Null while the block is a draft in someone's composer; set when the post is saved.
            $table->unsignedInteger('post_id')->nullable();
            $table->unsignedInteger('discussion_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('type', 12);
            $table->string('question', 200);
            // The two button labels (Agree/Disagree, Yes/No, This-or-That); null for Rating.
            $table->json('labels')->nullable();
            // This-or-That pictures: two paths on the assets disk, or nulls.
            $table->json('images')->nullable();
            $table->string('results', 8);
            $table->boolean('allow_change')->default(true);
            $table->boolean('allow_remove')->default(true);
            $table->boolean('anonymous')->default(false);
            $table->boolean('badges')->default(true);
            $table->dateTime('closes_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            // Recounted from stance_votes under the row lock on every vote.
            $table->unsignedInteger('vote_count')->default(0);
            $table->json('counts')->nullable();
            // Set while the post's text no longer holds this block's marker: the block and its votes are kept.
            $table->dateTime('hidden_at')->nullable();
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('post_id');
            $table->index(['discussion_id', 'post_id']);
            $table->index(['user_id', 'post_id']);
            $table->index('created_at');
        });

        $schema->create('stance_votes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('block_id');
            $table->unsignedInteger('user_id');
            // 0 or 1 for the two-button types, 1-5 for Rating.
            $table->unsignedTinyInteger('choice');
            $table->timestamps();

            $table->unique(['block_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('block_id')->references('id')->on('stance_blocks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Every vote, change and removal (choice null), in order: badges show the side a member took when
        // they posted, Trending counts recent votes. Cleared with the block's votes on a reset.
        $schema->create('stance_vote_log', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('block_id');
            $table->unsignedInteger('user_id');
            $table->unsignedTinyInteger('choice')->nullable();
            $table->dateTime('created_at');

            $table->index(['block_id', 'user_id', 'id']);
            $table->index('created_at');
            $table->foreign('block_id')->references('id')->on('stance_blocks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->table('discussions', function (Blueprint $table) {
            $table->unsignedInteger('stance_featured_id')->nullable();
            // The block chosen by the author or a moderator to make the badges; null = the admin's rule.
            $table->unsignedInteger('stance_badge_id')->nullable();

            $table->foreign('stance_featured_id')->references('id')->on('stance_blocks')->nullOnDelete();
            $table->foreign('stance_badge_id')->references('id')->on('stance_blocks')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropForeign(['stance_featured_id']);
            $table->dropForeign(['stance_badge_id']);
            $table->dropColumn(['stance_featured_id', 'stance_badge_id']);
        });
        $schema->dropIfExists('stance_vote_log');
        $schema->dropIfExists('stance_votes');
        $schema->dropIfExists('stance_blocks');
    },
];
