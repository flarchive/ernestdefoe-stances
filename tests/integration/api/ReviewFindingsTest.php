<?php

namespace ErnestDefoe\Stances\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Console\TickCommand;
use ErnestDefoe\Stances\Listener\FollowPosts;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** One test per finding of the independent review (2026-11-11), through the real endpoints. */
class ReviewFindingsTest extends TestCase
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

    private function draft(array $body = [], int $as = 2): array
    {
        [$status, $json] = $this->call('POST', '/api/stances', $as, $body + ['type' => 'agree', 'question' => 'The trade deadline should move to week 10.', 'labels' => ['Agree', 'Disagree']]);
        $this->assertSame(201, $status, json_encode($json));

        return $json['data'];
    }

    /** @return array{0: int, 1: int} */
    private function discussion(string $content, int $as = 2, array $relationships = []): array
    {
        [$status, $json] = $this->call('POST', '/api/discussions', $as, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Owners meeting', 'content' => $content], 'relationships' => $relationships]]);
        $this->assertSame(201, $status, json_encode($json));

        return [(int) $json['data']['id'], (int) $json['data']['relationships']['firstPost']['data']['id']];
    }

    private function vote(int $id, ?int $choice, int $as): array
    {
        return $choice === null ? $this->call('DELETE', "/api/stances/{$id}/vote", $as) : $this->call('POST', "/api/stances/{$id}/vote", $as, ['choice' => $choice]);
    }

    private function tick(): void
    {
        $command = $this->app()->getContainer()->make(TickCommand::class);
        $command->setLaravel($this->app()->getContainer());
        $command->run(new ArrayInput([]), new NullOutput());
    }

    private function db()
    {
        return $this->app()->getContainer()->make('db');
    }

    private function png(int $w = 20, int $h = 20): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /**
     * A GIF of $frames full 2000×2000 frames: about 3.6 KB a frame on disk, 16 MB a frame decoded.
     * Decoding every frame of 40 took 1.3 GB on the old code.
     */
    private function animatedGif(int $frames): string
    {
        $im = imagecreate(2000, 2000);
        imagecolorallocate($im, 255, 255, 255);
        ob_start();
        imagegif($im);
        $one = (string) ob_get_clean();
        $at = strpos($one, "\x2c", 13 + 3 * 2 ** ((ord($one[10]) & 7) + 1));
        $gif = substr($one, 0, $at)."\x21\xff\x0bNETSCAPE2.0\x03\x01\x00\x00\x00";
        $frame = substr($one, $at, -1);
        for ($i = 0; $i < $frames; $i++) {
            $gif .= "\x21\xf9\x04\x00\x01\x00\x00\x00".$frame;
        }

        return $gif.';';
    }

    private function upload(string $bytes, string $type, int $as): array
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'p', $type);
        $response = $this->send($this->request('POST', '/api/stances/images', ['authenticatedAs' => $as])->withUploadedFiles(['image' => $file]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function the_closed_alert_reaches_every_voter_past_one_batch_and_again_after_a_reopen()
    {
        $this->setting('ernestdefoe-stances.closed_alert', '1');
        $users = [];
        $groups = [];
        for ($id = 100; $id < 330; $id++) {
            $users[] = ['id' => $id, 'username' => "v$id", 'email' => "v$id@machine.local", 'is_email_confirmed' => 1];
            $groups[] = ['user_id' => $id, 'group_id' => Group::MEMBER_ID];
        }
        $this->prepareDatabase([User::class => $users, 'group_user' => $groups]);

        $block = $this->draft();
        $this->discussion("[stance={$block['id']}]");
        $now = Carbon::now();
        $this->db()->table('stance_votes')->insert(array_map(fn ($u) => ['block_id' => $block['id'], 'user_id' => $u['id'], 'choice' => 0, 'created_at' => $now, 'updated_at' => $now], $users));
        Block::query()->whereKey($block['id'])->update(['vote_count' => count($users), 'counts' => json_encode([count($users), 0])]);

        $this->call('PATCH', "/api/stances/{$block['id']}", 2, ['closed' => true]);
        $this->tick();

        $alerts = $this->db()->table('notifications')->where('type', 'stanceClosed');
        $this->assertSame(230, (clone $alerts)->count());
        $this->assertSame(0, (clone $alerts)->where('is_deleted', true)->count());

        // Reopened and closed again (a second later): a new alert for everyone, the first ones kept.
        $this->call('PATCH', "/api/stances/{$block['id']}", 2, ['closed' => false]);
        Block::query()->whereKey($block['id'])->update(['closed_at' => Carbon::now()->addSecond()]);
        $this->tick();
        $this->assertSame(460, (clone $alerts)->where('is_deleted', false)->count());
    }

    #[Test]
    public function the_closed_alert_and_the_notification_list_leave_out_blocks_the_voter_cannot_see()
    {
        $this->setting('ernestdefoe-stances.closed_alert', '1');
        $block = $this->draft();
        [$d, $post] = $this->discussion("[stance={$block['id']}]");
        $this->vote($block['id'], 0, 3);
        $this->vote($block['id'], 1, 4);
        $this->db()->table('discussions')->where('id', $d)->update(['is_private' => true]);

        Block::query()->whereKey($block['id'])->update(['closed_at' => Carbon::now()]);
        $this->tick();
        $this->assertSame(0, $this->db()->table('notifications')->where('type', 'stanceClosed')->count());

        // An alert that exists from before the discussion went private doesn't show either.
        $this->db()->table('notifications')->insert(['user_id' => 3, 'type' => 'stanceClosed', 'subject_id' => $block['id'], 'created_at' => Carbon::now()]);
        [, $json] = $this->call('GET', '/api/notifications', 3);
        $this->assertSame([], array_filter($json['data'], fn ($n) => $n['attributes']['contentType'] === 'stanceClosed'));
        unset($post);
    }

    #[Test]
    public function the_count_and_the_needs_tab_follow_the_per_tag_vote_permission()
    {
        $this->extension('flarum-tags');
        $this->setting('flarum-tags.min_primary_tags', '1');
        $this->setting('flarum-tags.max_primary_tags', '1');
        $this->setting('flarum-tags.min_secondary_tags', '0');
        $this->setting('flarum-tags.max_secondary_tags', '0');
        $this->prepareDatabase([
            'tags' => [['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'is_restricted' => false], ['id' => 2, 'name' => 'Read only', 'slug' => 'read-only', 'position' => 1, 'is_restricted' => true]],
            'group_permission' => [
                ['permission' => 'tag2.viewForum', 'group_id' => Group::MEMBER_ID],
                ['permission' => 'tag2.startDiscussion', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'tag2.discussion.stances.create', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'tag2.discussion.reply', 'group_id' => Group::MEMBER_ID],
            ],
        ]);
        $tag = fn (int $id) => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $id]]]];

        $open = $this->draft();
        $this->discussion("[stance={$open['id']}]", 2, $tag(1));
        $closed = $this->draft([], 1);
        $this->discussion("[stance={$closed['id']}]", 1, $tag(2));

        [, $forum] = $this->call('GET', '/api', 3);
        $this->assertSame(1, $forum['data']['attributes']['stancesNeedVote']);
        $this->assertSame([$open['id']], array_column($this->call('GET', '/api/stances?filter[tab]=needs', 3)[1]['data'], 'id'));
        $this->assertSame(403, $this->vote($closed['id'], 0, 3)[0]);
    }

    #[Test]
    public function votes_are_rate_limited_and_a_vote_that_changes_nothing_costs_nothing()
    {
        $blocks = [];
        $markers = '';
        for ($i = 0; $i < 3; $i++) {
            $blocks[] = $b = $this->draft();
            $markers .= "[stance={$b['id']}]\n\n";
        }
        $this->discussion($markers);
        $id = $blocks[0]['id'];

        $this->vote($id, 0, 3);
        $before = $this->db()->table('stance_vote_log')->count();
        for ($i = 0; $i < 70; $i++) {
            $this->assertSame(200, $this->vote($id, 0, 3)[0], 'repeating the same vote');
        }
        $this->assertSame($before, $this->db()->table('stance_vote_log')->count());

        $statuses = [];
        for ($i = 0; $i < 70; $i++) {
            $statuses[] = $this->vote($id, $i % 2 ? 0 : 1, 3)[0];
        }
        $this->assertContains(429, $statuses);
        $this->assertSame(59, count(array_filter($statuses, fn ($s) => $s === 200)), '60 changes a minute, the first vote included');
    }

    #[Test]
    public function uploads_are_re_encoded_rate_limited_and_claimable_only_by_their_uploader()
    {
        [$status, $json] = $this->upload($this->png(), 'image/png', 3);
        $this->assertSame(201, $status, json_encode($json));
        $path = $json['data']['path'];
        $this->assertMatchesRegularExpression('#^stances/[a-z0-9]{20}\.webp$#', $path);

        // Not an image: refused.
        $this->assertSame(422, $this->upload('<?php echo 1;', 'image/png', 3)[0]);

        // Someone else can't put dana's picture on their block.
        [, $json] = $this->call('POST', '/api/stances', 4, ['type' => 'pick', 'question' => 'Home or away?', 'labels' => ['Home', 'Away'], 'images' => [$path, null]]);
        $this->assertSame([null, null], $json['data']['images']);
        [, $json] = $this->call('POST', '/api/stances', 3, ['type' => 'pick', 'question' => 'Home or away?', 'labels' => ['Home', 'Away'], 'images' => [$path, null]]);
        $this->assertNotNull($json['data']['images'][0]);

        $statuses = [];
        for ($i = 0; $i < 31; $i++) {
            $statuses[] = $this->upload($this->png(), 'image/png', 4)[0];
        }
        $this->assertSame(429, end($statuses));
    }

    #[Test]
    public function a_long_animation_is_read_as_its_first_frame()
    {
        // ru_maxrss counts GD's buffers, which PHP's own memory figures don't. Bytes on macOS, KB on Linux.
        $rss = fn () => getrusage()['ru_maxrss'] * (PHP_OS_FAMILY === 'Darwin' ? 1 : 1024);
        $before = $rss();
        [$status, $json] = $this->upload($this->animatedGif(300), 'image/gif', 3);
        $this->assertSame(201, $status, json_encode($json));
        $this->assertLessThan(300 * 1024 * 1024, $rss() - $before);
    }

    #[Test]
    public function hidden_posts_and_locked_discussions_take_no_votes()
    {
        $this->extension('flarum-lock');
        $a = $this->draft();
        [$d, $post] = $this->discussion("[stance={$a['id']}]");

        $this->db()->table('posts')->where('id', $post)->update(['hidden_at' => Carbon::now()]);
        $this->assertSame(404, $this->vote($a['id'], 0, 3)[0], 'a member cannot even see it');
        [$status, $json] = $this->vote($a['id'], 0, 5);
        $this->assertSame(422, $status);
        $this->assertSame('stances_post_hidden', $json['errors'][0]['code']);

        $this->db()->table('posts')->where('id', $post)->update(['hidden_at' => null]);
        $this->db()->table('discussions')->where('id', $d)->update(['is_locked' => true]);
        [, $json] = $this->call('GET', "/api/stances/{$a['id']}", 5);
        $this->assertFalse($json['data']['canVote']);
        [$status, $json] = $this->vote($a['id'], 0, 5);
        $this->assertSame('stances_discussion_locked', $json['errors'][0]['code'], 'moderators included');
    }

    #[Test]
    public function a_posts_blocks_are_listed_only_for_whoever_may_edit_it()
    {
        $a = $this->draft();
        [, $post] = $this->discussion("[stance={$a['id']}]");

        $this->assertSame(403, $this->call('GET', "/api/stances?filter[post]={$post}", 3)[0]);
        $this->assertSame(200, $this->call('GET', "/api/stances?filter[post]={$post}", 1)[0]);
    }

    #[Test]
    public function the_gdpr_export_holds_the_members_votes_with_their_words()
    {
        $this->extension('flarum-gdpr');
        $a = $this->draft();
        $this->discussion("[stance={$a['id']}]");
        $this->vote($a['id'], 1, 3);

        $data = $this->app()->getContainer()->make(\ErnestDefoe\Stances\Gdpr\StancesData::class, ['user' => User::query()->find(3), 'erasureRequest' => null]);
        $rows = json_decode($data->export()[0]['stances/stances.json'], true);
        $this->assertSame('The trade deadline should move to week 10.', $rows['votes'][0]['question']);
        $this->assertSame('Disagree', $rows['votes'][0]['choice']);
    }

    #[Test]
    public function blocks_follow_posts_moved_to_another_discussion_and_survive_the_old_ones_deletion()
    {
        // The admin replies (core doesn't throttle admins); the discussions are other members'.
        $a = $this->draft([], 1);
        [$from] = $this->discussion('Start here.', 3);
        [$to] = $this->discussion('Merge target.', 4);
        [, $reply] = $this->call('POST', '/api/posts', 1, ['data' => ['type' => 'posts', 'attributes' => ['content' => "[stance={$a['id']}]"], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $from]]]]]);
        $replyId = (int) $reply['data']['id'];
        $this->assertSame(200, $this->vote($a['id'], 0, 4)[0]);
        $this->call('POST', "/api/stances/{$a['id']}/feature", 3, ['on' => true]);

        // What fof/merge-discussions does: move the post with a plain update, then delete the old discussion.
        $this->db()->table('posts')->where('id', $replyId)->update(['discussion_id' => $to, 'number' => 99]);
        $this->assertSame(204, $this->call('DELETE', "/api/discussions/{$from}", 1)[0]);

        $this->assertSame(1, Block::query()->whereKey($a['id'])->count(), 'the moved block survives');
        $this->assertSame(1, $this->db()->table('stance_votes')->where('block_id', $a['id'])->count());

        $this->app()->getContainer()->make(FollowPosts::class)->resync();
        $this->assertSame($to, Block::query()->find($a['id'])->discussion_id);
    }

    #[Test]
    public function show_replies_from_returns_one_sides_posts_and_waits_for_results()
    {
        $block = $this->draft(['results' => 'always'], 1);
        [$d, $first] = $this->discussion("[stance={$block['id']}]", 1);
        $this->vote($block['id'], 0, 3);
        $this->vote($block['id'], 1, 4);
        $reply = fn (int $as, string $text) => (int) $this->call('POST', '/api/posts', $as, ['data' => ['type' => 'posts', 'attributes' => ['content' => $text], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $d]]]]])[1]['data']['id'];
        $agree = $reply(3, 'Week 10 is fairer.');
        $disagree = $reply(4, 'Week 8 keeps it honest.');

        $this->assertSame([(string) $first, (string) $agree], $this->call('GET', "/api/stances/{$block['id']}/sides?choice=0", null)[1]['data']);
        $this->assertSame([(string) $first, (string) $disagree], $this->call('GET', "/api/stances/{$block['id']}/sides?choice=1", null)[1]['data']);

        // Results after voting: a member who hasn't voted can't sort replies by side either.
        $hidden = $this->draft(['results' => 'voted'], 5);
        $this->discussion("[stance={$hidden['id']}]", 5);
        $this->vote($hidden['id'], 0, 3);
        $this->assertSame(403, $this->call('GET', "/api/stances/{$hidden['id']}/sides?choice=0", 4)[0]);
        $this->vote($hidden['id'], 1, 4);
        $this->assertSame(200, $this->call('GET', "/api/stances/{$hidden['id']}/sides?choice=0", 4)[0]);
    }

    #[Test]
    public function editing_a_block_keeps_its_past_close_date_and_its_closed_state()
    {
        $block = $this->draft(['closesAt' => Carbon::now()->addHour()->toIso8601String()]);
        $this->discussion("[stance={$block['id']}]");
        Block::query()->whereKey($block['id'])->update(['closes_at' => Carbon::now()->subMinute()]);
        $old = Block::query()->find($block['id'])->closes_at->toIso8601String();

        // A moderator edits the wording, the dialog sending the old (past) date back, or none.
        foreach ([$old, null] as $sent) {
            [$status, $json] = $this->call('PATCH', "/api/stances/{$block['id']}", 5, ['question' => 'Reworded: week 10?', 'labels' => ['Agree', 'Disagree'], 'closesAt' => $sent]);
            $this->assertSame(200, $status, json_encode($json));
            $this->assertTrue($json['data']['closed'], 'still closed');
        }
    }

    #[Test]
    public function changing_a_drafts_type_resets_its_counts()
    {
        $block = $this->draft();
        [, $json] = $this->call('PATCH', "/api/stances/{$block['id']}", 2, ['type' => 'rating', 'question' => 'Rate the charts']);
        $this->assertSame([0, 0, 0, 0, 0], Block::query()->find($block['id'])->counts);
        $this->assertSame('rating', $json['data']['type']);
    }

    #[Test]
    public function a_featured_pointer_never_shows_a_block_from_another_discussion()
    {
        $a = $this->draft([], 1);
        [$from] = $this->discussion('First.', 3);
        [$to] = $this->discussion('Second.', 4);
        [, $reply] = $this->call('POST', '/api/posts', 1, ['data' => ['type' => 'posts', 'attributes' => ['content' => "[stance={$a['id']}]"], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $from]]]]]);
        $this->call('POST', "/api/stances/{$a['id']}/feature", 3, ['on' => true]);

        // The post moves to the other discussion (a split or a swap).
        $this->db()->table('posts')->where('id', (int) $reply['data']['id'])->update(['discussion_id' => $to, 'number' => 50]);
        $this->app()->getContainer()->make(FollowPosts::class)->resync();

        $this->assertNull($this->db()->table('discussions')->where('id', $from)->value('stance_featured_id'));
        [, $json] = $this->call('GET', "/api/discussions/{$from}", 3);
        $this->assertNull($json['data']['attributes']['stanceFeatured']);
    }

    #[Test]
    public function show_replies_from_is_refused_where_sides_are_not_public()
    {
        // The badge block is the first sided, public block in the opening post: created first.
        $badge = $this->draft(['results' => 'always'], 1);
        $anon = $this->draft(['anonymous' => true, 'results' => 'always'], 1);
        $second = $this->draft(['type' => 'yesno', 'question' => 'Second?', 'labels' => ['Yes', 'No'], 'results' => 'always'], 1);
        [, $post] = $this->discussion("[stance={$badge['id']}]\n\n[stance={$anon['id']}]\n\n[stance={$second['id']}]", 1);

        $this->assertSame(200, $this->call('GET', "/api/stances/{$badge['id']}/sides?choice=1", 3)[0]);
        $this->assertSame(403, $this->call('GET', "/api/stances/{$anon['id']}/sides?choice=0", 3)[0], 'anonymous');
        $this->assertSame(403, $this->call('GET', "/api/stances/{$second['id']}/sides?choice=0", 3)[0], 'not the badge block');
        [$status, $json] = $this->call('GET', "/api/stances/{$badge['id']}/sides?choice=7", 3);
        $this->assertSame('stances_bad_side', $json['errors'][0]['code']);

        $this->db()->table('posts')->where('id', $post)->update(['hidden_at' => Carbon::now()]);
        $this->assertSame(404, $this->call('GET', "/api/stances/{$badge['id']}/sides?choice=0", 3)[0], 'hidden post');
        unset($status);
    }

    #[Test]
    public function the_closed_alert_checks_each_voter_in_a_restricted_tag()
    {
        $this->extension('flarum-tags');
        $this->setting('flarum-tags.min_primary_tags', '1');
        $this->setting('flarum-tags.max_primary_tags', '1');
        $this->setting('flarum-tags.min_secondary_tags', '0');
        $this->setting('flarum-tags.max_secondary_tags', '0');
        $this->setting('ernestdefoe-stances.closed_alert', '1');
        $this->prepareDatabase([
            'tags' => [['id' => 1, 'name' => 'Open', 'slug' => 'open', 'position' => 0, 'is_restricted' => false], ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true]],
            'group_permission' => [['permission' => 'tag2.viewForum', 'group_id' => Group::MODERATOR_ID]],
        ]);

        $block = $this->draft([], 1);
        [$d] = $this->discussion("[stance={$block['id']}]", 1, ['tags' => ['data' => [['type' => 'tags', 'id' => '1']]]]);
        $this->assertSame(200, $this->vote($block['id'], 0, 3)[0]);
        $this->assertSame(200, $this->vote($block['id'], 1, 5)[0]);

        // The discussion moves to a staff-only tag: the member can't see the block any more, the moderator can.
        $this->db()->table('discussion_tag')->where('discussion_id', $d)->update(['tag_id' => 2]);
        Block::query()->whereKey($block['id'])->update(['closed_at' => Carbon::now()]);
        $this->tick();

        $this->assertSame([5], $this->db()->table('notifications')->where('type', 'stanceClosed')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        unset($d);
    }

    #[Test]
    public function a_picture_too_large_to_decode_is_refused_with_a_clear_message()
    {
        $im = imagecreate(6000, 6000);
        imagecolorallocate($im, 255, 255, 255);
        ob_start();
        imagepng($im);
        [$status, $json] = $this->upload((string) ob_get_clean(), 'image/png', 3);
        $this->assertSame(422, $status);
        $this->assertSame('stances_image_too_big', $json['errors'][0]['code']);
    }
}
