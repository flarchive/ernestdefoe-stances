<?php

namespace ErnestDefoe\Stances\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Vote;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class StancesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-stances');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'lee', 'email' => 'lee@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $response = $this->send($this->request($method, $path, $options)->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /** @return array<string, mixed> */
    private function draft(array $body = [], int $as = 2): array
    {
        [$status, $json] = $this->call('POST', '/api/stances', $as, $body + [
            'type' => 'agree',
            'question' => 'The trade deadline should move to week 10.',
            'labels' => ['Agree', 'Disagree'],
        ]);
        $this->assertSame(201, $status, json_encode($json));

        return $json['data'];
    }

    /** A discussion whose first post holds these markers. @return array{0: int, 1: int} discussion id, post id */
    private function discussion(string $content, int $as = 2, array $relationships = []): array
    {
        [$status, $json] = $this->call('POST', '/api/discussions', $as, ['data' => [
            'type' => 'discussions',
            'attributes' => ['title' => 'Owners meeting', 'content' => $content],
            'relationships' => $relationships,
        ]]);
        $this->assertSame(201, $status, json_encode($json));

        return [(int) $json['data']['id'], (int) $json['data']['relationships']['firstPost']['data']['id']];
    }

    /** @return array{0: int, 1: array<string, mixed>|null} */
    private function reply(int $discussionId, string $content, int $as): array
    {
        return $this->call('POST', '/api/posts', $as, ['data' => [
            'type' => 'posts',
            'attributes' => ['content' => $content],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussionId]]],
        ]]);
    }

    private function vote(int $id, ?int $choice, ?int $as): array
    {
        return $choice === null
            ? $this->call('DELETE', "/api/stances/{$id}/vote", $as)
            : $this->call('POST', "/api/stances/{$id}/vote", $as, ['choice' => $choice]);
    }

    /** @return array<int, array<string, mixed>> post id => attributes */
    private function posts(int $discussionId, ?int $as): array
    {
        [, $json] = $this->call('GET', "/api/discussions/{$discussionId}", $as);
        $out = [];
        foreach ($json['included'] ?? [] as $item) {
            if ($item['type'] === 'posts') {
                $out[(int) $item['id']] = $item['attributes'];
            }
        }

        return $out;
    }

    #[Test]
    public function a_draft_joins_its_post_through_the_marker_and_shows_there()
    {
        $block = $this->draft();
        $this->assertNull($block['postId']);

        [$d, $post] = $this->discussion("Vote below.\n\n[stance={$block['id']}]\n");

        $this->assertSame($post, Block::query()->find($block['id'])->post_id);
        $attributes = $this->posts($d, 3)[$post];
        $this->assertSame([$block['id']], array_column($attributes['stanceBlocks'], 'id'));
        $this->assertStringContainsString('data-stance="'.$block['id'].'"', $attributes['contentHtml']);
    }

    #[Test]
    public function someone_elses_draft_never_joins_a_post()
    {
        $block = $this->draft([], 3);
        [$d, $post] = $this->discussion("[stance={$block['id']}]");

        $this->assertNull(Block::query()->find($block['id'])->post_id);
        $this->assertSame([], $this->posts($d, 2)[$post]['stanceBlocks']);
    }

    #[Test]
    public function a_post_holds_at_most_the_limit()
    {
        $markers = '';
        for ($i = 0; $i < 4; $i++) {
            $markers .= '[stance='.$this->draft()['id']."]\n\n";
        }

        [$status, $json] = $this->call('POST', '/api/discussions', 2, ['data' => [
            'type' => 'discussions',
            'attributes' => ['title' => 'Too many', 'content' => $markers],
        ]]);

        $this->assertSame(422, $status);
        $this->assertSame('/data/attributes/stances', $json['errors'][0]['source']['pointer']);
        $this->assertSame(0, Block::query()->whereNotNull('post_id')->count());
    }

    #[Test]
    public function members_vote_change_and_take_back_and_counts_follow()
    {
        $block = $this->draft();
        [, $post] = $this->discussion("[stance={$block['id']}]");
        $id = $block['id'];

        $this->assertSame([1, 0], $this->vote($id, 0, 3)[1]['data']['counts']);
        [, $json] = $this->vote($id, 1, 4);
        $this->assertSame([1, 1], $json['data']['counts']);
        $this->assertSame(1, $json['data']['mine']);

        // Changing keeps one vote per member.
        $this->assertSame([0, 2], $this->vote($id, 1, 3)[1]['data']['counts']);
        // Taken back: dana no longer sees results ("after voting"), but the count is right.
        $this->assertSame(1, $this->vote($id, null, 3)[1]['data']['voteCount']);
        $this->assertSame([0, 1], Block::query()->find($id)->counts);
        $this->assertSame(1, Vote::query()->count());

        $this->assertSame(422, $this->vote($id, 7, 3)[0]);
        // A guest is turned away (CSRF first, then the log-in check).
        $this->assertContains($this->vote($id, 0, null)[0], [400, 401]);
        unset($post);
    }

    #[Test]
    public function locked_votes_and_closed_blocks_refuse_changes()
    {
        $block = $this->draft(['allowChange' => false]);
        $this->discussion("[stance={$block['id']}]");
        $id = $block['id'];

        $this->vote($id, 0, 3);
        [$status, $json] = $this->vote($id, 1, 3);
        $this->assertSame(422, $status);
        $this->assertSame('stances_locked_vote', $json['errors'][0]['code']);
        $this->assertSame(422, $this->vote($id, null, 3)[0]);

        // The author closes it; then nobody votes.
        $this->assertSame(200, $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => true])[0]);
        $this->assertSame(403, $this->vote($id, 0, 4)[0]);
        // A date in the past closes it too.
        Block::query()->whereKey($id)->update(['closed_at' => null, 'closes_at' => Carbon::now()->subMinute()]);
        $this->assertSame(403, $this->vote($id, 0, 4)[0]);
    }

    #[Test]
    public function results_stay_hidden_until_the_viewer_may_see_them()
    {
        $after = $this->draft();
        $closing = $this->draft(['results' => 'closed']);
        $this->discussion("[stance={$after['id']}]\n\n[stance={$closing['id']}]");
        $this->vote($after['id'], 0, 3);
        $this->vote($closing['id'], 0, 3);

        [, $json] = $this->call('GET', "/api/stances/{$after['id']}", 4);
        $this->assertNull($json['data']['counts']);
        $this->assertSame(1, $json['data']['voteCount']);
        $this->assertSame(403, $this->call('GET', "/api/stances/{$after['id']}/voters", 4)[0]);

        $this->assertSame([1, 1], $this->vote($after['id'], 1, 4)[1]['data']['counts']);
        [, $json] = $this->call('GET', "/api/stances/{$closing['id']}", 3);
        $this->assertNull($json['data']['counts'], 'after-close results stay hidden from a voter');

        // Moderators always see them.
        [, $json] = $this->call('GET', "/api/stances/{$closing['id']}", 5);
        $this->assertSame([1, 0], $json['data']['counts']);
    }

    #[Test]
    public function anonymous_blocks_never_show_voters_never_make_badges_and_stay_anonymous()
    {
        $block = $this->draft(['anonymous' => true]);
        [$d, $post] = $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 2);
        $this->vote($block['id'], 0, 3);

        $this->assertFalse($block['badges']);
        $this->assertSame(403, $this->call('GET', "/api/stances/{$block['id']}/voters", 5)[0]);
        $this->assertNull($this->posts($d, 3)[$post]['stanceSide']);

        [$status, $json] = $this->call('PATCH', "/api/stances/{$block['id']}", 2, ['anonymous' => false]);
        $this->assertSame(422, $status);
        $this->assertSame('stances_anonymous_final', $json['errors'][0]['code']);
    }

    #[Test]
    public function badges_show_each_members_side_and_when_they_switched()
    {
        $block = $this->draft();
        [$d] = $this->discussion("[stance={$block['id']}]");

        $this->vote($block['id'], 0, 3);
        Vote::query()->update(['created_at' => Carbon::now()->subMinutes(5)]);
        $this->app()->getContainer()->make('db')->table('stance_vote_log')->update(['created_at' => Carbon::now()->subMinutes(5)]);
        [, $reply] = $this->reply($d, 'Week 10 is fairer.', 3);
        $replyId = (int) $reply['data']['id'];

        // Results "after voting": lee sees no sides until lee votes; a moderator always does.
        $this->assertNull($this->posts($d, 4)[$replyId]['stanceSide']);
        $this->assertSame('Agree', $this->posts($d, 5)[$replyId]['stanceSide']['label']);
        $this->vote($block['id'], 0, 4);

        $this->assertSame(['block' => $block['id'], 'choice' => 0, 'label' => 'Agree', 'was' => null], $this->posts($d, 4)[$replyId]['stanceSide']);

        $this->app()->getContainer()->make('db')->table('posts')->where('id', $replyId)->update(['created_at' => Carbon::now()->subMinutes(2)]);
        $this->vote($block['id'], 1, 3);
        $this->assertSame(['block' => $block['id'], 'choice' => 1, 'label' => 'Disagree', 'was' => 'Agree'], $this->posts($d, 4)[$replyId]['stanceSide']);

        // Taking the vote back takes the badge away.
        $this->vote($block['id'], null, 3);
        $this->assertNull($this->posts($d, 4)[$replyId]['stanceSide']);
    }

    #[Test]
    public function badges_wait_for_results_on_a_block_that_shows_them_after_it_closes()
    {
        $block = $this->draft(['results' => 'closed']);
        [$d, $post] = $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 2);
        $this->vote($block['id'], 1, 3);

        $this->assertNull($this->posts($d, 3)[$post]['stanceSide'], 'even a voter waits for the close');
        $this->assertNull($this->posts($d, null)[$post]['stanceSide']);

        $this->call('PATCH', "/api/stances/{$block['id']}", 2, ['closed' => true]);
        $this->assertSame('Agree', $this->posts($d, null)[$post]['stanceSide']['label']);
    }

    #[Test]
    public function the_admins_rule_decides_which_block_makes_badges()
    {
        $this->setting('ernestdefoe-stances.badge_rule', 'off');
        $block = $this->draft();
        [$d, $post] = $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 2);

        $this->assertNull($this->posts($d, 3)[$post]['stanceSide']);
    }

    #[Test]
    public function the_starter_or_a_moderator_picks_the_badge_block()
    {
        $this->setting('ernestdefoe-stances.badge_rule', 'picked');
        $first = $this->draft();
        $second = $this->draft(['type' => 'yesno', 'question' => 'Second wildcard round?', 'labels' => ['Yes', 'No']]);
        [$d, $post] = $this->discussion("[stance={$first['id']}]\n\n[stance={$second['id']}]");
        $this->vote($first['id'], 0, 2);
        $this->vote($second['id'], 1, 2);

        $this->assertNull($this->posts($d, 3)[$post]['stanceSide'], 'nothing picked yet');

        $this->assertSame(403, $this->call('POST', "/api/stances/{$second['id']}/badge", 3, ['on' => true])[0]);
        $this->assertSame(200, $this->call('POST', "/api/stances/{$second['id']}/badge", 2, ['on' => true])[0]);
        $this->vote($second['id'], 0, 3);
        $this->assertSame('No', $this->posts($d, 3)[$post]['stanceSide']['label']);
    }

    #[Test]
    public function taking_the_marker_out_hides_the_block_and_putting_it_back_restores_it()
    {
        $block = $this->draft();
        [$d, $post] = $this->discussion("Before.\n\n[stance={$block['id']}]");
        $this->vote($block['id'], 0, 3);

        // As the admin: core throttles a member's repeated saves.
        $edit = fn (string $content) => $this->call('PATCH', "/api/posts/{$post}", 1, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['content' => $content]]]);

        $this->assertSame(200, $edit('Changed my mind.')[0]);
        $this->assertNotNull(Block::query()->find($block['id'])->hidden_at);
        $this->assertSame([], $this->posts($d, 3)[$post]['stanceBlocks']);
        $this->assertSame(404, $this->vote($block['id'], 1, 3)[0]);

        $this->assertSame(200, $edit("Back.\n\n[stance={$block['id']}]")[0]);
        $this->assertSame(1, $this->posts($d, 3)[$post]['stanceBlocks'][0]['voteCount']);
    }

    #[Test]
    public function only_moderators_reset_votes()
    {
        $block = $this->draft();
        $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 3);

        $this->assertSame(403, $this->call('POST', "/api/stances/{$block['id']}/reset", 2)[0]);
        [$status, $json] = $this->call('POST', "/api/stances/{$block['id']}/reset", 5);
        $this->assertSame(200, $status);
        $this->assertSame(0, $json['data']['voteCount']);
        $this->assertSame(0, $this->app()->getContainer()->make('db')->table('stance_vote_log')->count());
    }

    #[Test]
    public function the_stances_page_lists_what_the_viewer_can_see_in_every_tab()
    {
        $a = $this->draft();
        $b = $this->draft(['closesAt' => Carbon::now()->addDay()->toIso8601String()]);
        $this->discussion("[stance={$a['id']}]\n\n[stance={$b['id']}]");
        $this->vote($a['id'], 0, 3);

        $ids = fn (string $tab, int $as) => array_column($this->call('GET', "/api/stances?filter[tab]={$tab}", $as)[1]['data'], 'id');

        $this->assertSame([$a['id']], $ids('trending', 4));
        $this->assertSame([$b['id'], $a['id']], $ids('new', 4));
        $this->assertSame([$b['id']], $ids('closing', 4));
        $this->assertSame([$b['id']], $ids('needs', 3));

        // A draft is nobody's to list.
        $this->draft();
        $this->assertCount(2, $ids('new', 4));
    }

    #[Test]
    public function blocks_in_a_discussion_nobody_else_can_see_stay_hidden()
    {
        $block = $this->draft();
        [$d] = $this->discussion("[stance={$block['id']}]");
        $this->app()->getContainer()->make('db')->table('discussions')->where('id', $d)->update(['is_private' => true]);

        $this->assertSame(404, $this->call('GET', "/api/stances/{$block['id']}", 3)[0]);
        $this->assertSame(404, $this->vote($block['id'], 0, 3)[0]);
        $this->assertSame([], $this->call('GET', '/api/stances?filter[tab]=new', 3)[1]['data']);
    }

    #[Test]
    public function deleting_the_discussion_deletes_its_blocks_and_votes()
    {
        $block = $this->draft();
        [$d] = $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 3);

        $this->assertSame(204, $this->call('DELETE', "/api/discussions/{$d}", 1)[0]);
        $this->assertSame(0, Block::query()->count());
        $this->assertSame(0, Vote::query()->count());
    }

    #[Test]
    public function tag_permissions_decide_who_may_add_blocks()
    {
        $this->extension('flarum-tags');
        $this->setting('flarum-tags.min_primary_tags', '1');
        $this->setting('flarum-tags.max_primary_tags', '1');
        $this->setting('flarum-tags.min_secondary_tags', '0');
        $this->setting('flarum-tags.max_secondary_tags', '0');
        $this->prepareDatabase([
            'tags' => [['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'is_restricted' => false], ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true]],
            'group_permission' => [
                ['permission' => 'tag2.viewForum', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'tag2.startDiscussion', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'tag2.discussion.reply', 'group_id' => Group::MEMBER_ID],
            ],
        ]);

        $tag = fn (int $id) => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $id]]]];

        $open = $this->draft();
        $this->discussion("[stance={$open['id']}]", 2, $tag(1));
        $this->assertNotNull(Block::query()->find($open['id'])->post_id);

        // Another member: core throttles a member's second discussion in a row.
        $staff = $this->draft([], 3);
        [$status, $json] = $this->call('POST', '/api/discussions', 3, ['data' => [
            'type' => 'discussions',
            'attributes' => ['title' => 'Staff only', 'content' => "[stance={$staff['id']}]"],
            'relationships' => $tag(2),
        ]]);
        $this->assertSame(422, $status, json_encode($json));
        $this->assertNull(Block::query()->find($staff['id'])->post_id);
    }

    #[Test]
    public function the_tick_marks_closed_blocks_and_alerts_voters_only_when_switched_on()
    {
        $this->setting('ernestdefoe-stances.closed_alert', '1');
        $block = $this->draft();
        $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 3);
        $this->vote($block['id'], 1, 4);
        Block::query()->whereKey($block['id'])->update(['closes_at' => Carbon::now()->subMinute()]);

        $this->app();
        $command = $this->app()->getContainer()->make(\ErnestDefoe\Stances\Console\TickCommand::class);
        $command->setLaravel($this->app()->getContainer());
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());

        $db = $this->app()->getContainer()->make('db');
        $this->assertSame(2, $db->table('notifications')->where('type', 'stanceClosed')->count());
        $this->assertNotNull(Block::query()->find($block['id'])->closed_notified_at);

        // Run again: nobody hears twice.
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
        $this->assertSame(2, $db->table('notifications')->where('type', 'stanceClosed')->count());

        // The notification list loads with this subject.
        [$status] = $this->call('GET', '/api/notifications', 3);
        $this->assertSame(200, $status);
    }

    #[Test]
    public function moderators_delete_any_block_and_members_only_their_drafts()
    {
        $block = $this->draft();
        [$d] = $this->discussion("[stance={$block['id']}]");
        $this->call('POST', "/api/stances/{$block['id']}/feature", 2, ['on' => true]);
        $this->vote($block['id'], 0, 3);

        $this->assertSame(403, $this->call('DELETE', "/api/stances/{$block['id']}", 2)[0]);
        $this->assertSame(204, $this->call('DELETE', "/api/stances/{$block['id']}", 5)[0]);
        $this->assertSame(0, Block::query()->count());
        $this->assertSame(0, Vote::query()->count());
        $this->assertNull($this->app()->getContainer()->make('db')->table('discussions')->where('id', $d)->value('stance_featured_id'));

        $draft = $this->draft([], 3);
        $this->assertSame(404, $this->call('DELETE', "/api/stances/{$draft['id']}", 4)[0]);
        $this->assertSame(204, $this->call('DELETE', "/api/stances/{$draft['id']}", 3)[0]);
    }

    #[Test]
    public function erasing_a_member_removes_their_votes_and_recounts()
    {
        $this->extension('flarum-gdpr');
        $block = $this->draft();
        $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 3);
        $this->vote($block['id'], 1, 4);

        $data = $this->app()->getContainer()->make(\ErnestDefoe\Stances\Gdpr\StancesData::class, ['user' => User::query()->find(3), 'erasureRequest' => null]);
        $export = $data->export();
        $this->assertStringContainsString('The trade deadline', json_encode($export));

        $data->delete();
        $this->assertSame(1, Block::query()->find($block['id'])->vote_count);
        $this->assertSame([0, 1], Block::query()->find($block['id'])->counts);
    }
}
