<?php

namespace ErnestDefoe\Stances\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/** The Floxum code review of 1.0.0 (2026-11-11), one test per finding. */
class FloxumReviewTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use JsonApiCalls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-stances');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'dana', 'email' => 'dana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'lee', 'email' => 'lee@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }


    private function draft(int $as = 2): int
    {
        [$status, $json] = $this->call('POST', '/api/stances', $as, ['type' => 'agree', 'question' => 'The trade deadline should move to week 10.', 'labels' => ['Agree', 'Disagree']]);
        $this->assertSame(201, $status, json_encode($json));

        return (int) $json['data']['id'];
    }

    /** @return array{0: int, 1: int} discussion id, first post id */
    private function discussion(string $content, int $as = 2): array
    {
        [$status, $json] = $this->call('POST', '/api/discussions', $as, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Owners meeting', 'content' => $content]]]);
        $this->assertSame(201, $status, json_encode($json));

        return [(int) $json['data']['id'], (int) $json['data']['relationships']['firstPost']['data']['id']];
    }

    /** The sidebar count from the forum resource, and whether working it out queried the votes table. */
    private function needCount(int $as): array
    {
        $db = $this->app()->getContainer()->make('db')->connection();
        $db->flushQueryLog();
        $db->enableQueryLog();
        [, $json] = $this->call('GET', '/api', $as);
        $queried = collect($db->getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'stance_votes'));
        $db->disableQueryLog();

        return [$json['data']['attributes']['stancesNeedVote'], $queried];
    }

    private function upload(string $bytes, string $type, int $as): array
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'p', $type);
        $response = $this->send($this->request('POST', '/api/stances/images', ['authenticatedAs' => $as])->withUploadedFiles(['image' => $file]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function the_sidebar_count_is_cached_per_member_and_follows_every_change()
    {
        $this->assertSame([0, true], $this->needCount(3));
        // Cached: the next page load runs no count.
        $this->assertSame([0, false], $this->needCount(3));

        // A block joins a post: every member's count is worked out again.
        $id = $this->draft();
        [, $post] = $this->discussion("[stance={$id}]");
        $this->assertSame([1, true], $this->needCount(3));
        $this->assertSame([1, false], $this->needCount(3));
        $this->assertSame([1, true], $this->needCount(4));

        // Her own vote forgets only her count.
        $this->assertSame(200, $this->call('POST', "/api/stances/{$id}/vote", 3, ['choice' => 0])[0]);
        $this->assertSame([0, true], $this->needCount(3));
        $this->assertSame([1, false], $this->needCount(4));

        // The author closes it, reopens it; a moderator hides the post.
        $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => true]);
        $this->assertSame([0, true], $this->needCount(4));
        $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => false]);
        $this->assertSame([1, true], $this->needCount(4));
        $this->assertSame(200, $this->call('PATCH', "/api/posts/{$post}", 1, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['isHidden' => true]]])[0]);
        $this->assertSame([0, true], $this->needCount(4));
    }

    #[Test]
    public function an_upload_is_judged_by_its_bytes_not_the_type_the_browser_claims()
    {
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();

        // Claimed to be a PNG, but HTML (or an SVG): refused before anything decodes it.
        $this->assertSame('stances_bad_image', $this->upload('<html><script>alert(1)</script></html>', 'image/png', 3)[1]['errors'][0]['code']);
        $this->assertSame(422, $this->upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image/png', 3)[0]);
        // A real PNG the browser didn't label: taken.
        [$status, $json] = $this->upload($png, 'application/octet-stream', 3);
        $this->assertSame(201, $status, json_encode($json));
    }

    /** @return array{int, array<string, mixed>|null, string} */
    private function raw(string $method, string $path, ?int $as, ?array $json = null, array $params = []): array
    {
        $options = ($as ? ['authenticatedAs' => $as] : []) + ($json !== null ? ['json' => $json] : []);
        $response = $this->send($this->request($method, $path, $options)->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $response->getHeaderLine('Content-Type')];
    }

    #[Test]
    public function blocks_are_a_json_api_resource_with_json_api_errors()
    {
        $attributes = ['blockType' => 'agree', 'question' => 'Week 10?', 'labels' => ['Agree', 'Disagree']];

        [$status, $json, $type] = $this->raw('POST', '/api/stances', 3, ['data' => ['type' => 'stances', 'attributes' => $attributes]]);
        $this->assertSame(201, $status, json_encode($json));
        $this->assertStringContainsString('application/vnd.api+json', $type);
        $this->assertSame('stances', $json['data']['type']);
        $this->assertIsString($json['data']['id']);
        $this->assertTrue($json['data']['attributes']['canEdit']);
        $this->assertSame('agree', $json['data']['attributes']['blockType']);
        $this->assertArrayNotHasKey('type', $json['data']['attributes'], 'reserved in JSON:API');
        $id = $json['data']['id'];

        // Show, Update and the actions answer with the same document.
        $this->assertSame('stances', $this->raw('GET', "/api/stances/{$id}", 3)[1]['data']['type']);
        [$status, $json] = $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['question' => 'Week 11?', 'labels' => ['Agree', 'Disagree']]]]);
        $this->assertSame([200, 'Week 11?'], [$status, $json['data']['attributes']['question']]);

        // Errors are JSON:API error objects carrying our codes.
        [$status, $json] = $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['anonymous' => true]]]);
        $this->assertSame(200, $status);
        [$status, $json, $type] = $this->raw('GET', '/api/stances/999999', 3);
        $this->assertSame([404, '404', 'stances_not_found'], [$status, $json['errors'][0]['status'], $json['errors'][0]['code']]);
        $this->assertStringContainsString('application/vnd.api+json', $type);

        // Fields the schema doesn't have, or that are read-only, are refused; guests can't write.
        $this->assertSame(400, $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['userId' => 1]]])[0]);
        $this->assertSame(403, $this->raw('PATCH', "/api/stances/{$id}", 3, ['data' => ['type' => 'stances', 'id' => $id, 'attributes' => ['voteCount' => 50]]])[0]);
        // (A guest without a session meets the CSRF check first: 400; with one, the endpoint's 401.)
        $before = \ErnestDefoe\Stances\Block::query()->count();
        $this->assertContains($this->raw('POST', '/api/stances', null, ['data' => ['type' => 'stances', 'attributes' => $attributes]])[0], [400, 401]);
        $this->assertContains($this->raw('DELETE', "/api/stances/{$id}", null)[0], [400, 401]);
        $this->assertSame($before, \ErnestDefoe\Stances\Block::query()->count());

        // Someone else's draft is as missing as one that doesn't exist; its author deletes it.
        $this->assertSame(404, $this->raw('DELETE', "/api/stances/{$id}", 4)[0]);
        $this->assertSame(204, $this->raw('DELETE', "/api/stances/{$id}", 3)[0]);
    }

    #[Test]
    public function the_stances_page_is_the_index_endpoint_twenty_at_a_time()
    {
        for ($d = 0; $d < 7; $d++) {
            $markers = [];
            for ($i = 0; $i < 3; $i++) {
                $markers[] = '[stance='.$this->draft(1).']';
            }
            $this->discussion(implode("\n\n", $markers), 1);
        }

        [$status, $json] = $this->raw('GET', '/api/stances', 3, null, ['filter' => ['tab' => 'new']]);
        $this->assertSame(200, $status, json_encode($json));
        $this->assertCount(20, $json['data']);
        $this->assertArrayHasKey('next', $json['links']);
        $firstId = $json['data'][0]['id'];
        $first = $json['data'][0]['attributes'];
        $this->assertSame('Owners meeting', $first['discussion']['title']);
        $this->assertSame(0, $first['votesToday']);
        $this->assertSame('admin', $first['author']['username']);
        $this->assertArrayNotHasKey('total', $json['meta']['page'] ?? [], 'nothing counts a total');

        [, $json] = $this->raw('GET', '/api/stances', 3, null, ['filter' => ['tab' => 'new'], 'page' => ['offset' => 20]]);
        $this->assertCount(1, $json['data']);

        // Only the page carries where a block is; Show doesn't.
        $this->assertArrayNotHasKey('votesToday', $this->raw('GET', "/api/stances/{$firstId}", 3)[1]['data']['attributes']);
    }

    #[Test]
    public function as_a_notifications_subject_a_block_carries_only_its_question_and_place()
    {
        $this->setting('ernestdefoe-stances.closed_alert', '1');
        $id = $this->draft();
        $this->discussion("[stance={$id}]");
        $this->assertSame(200, $this->call('POST', "/api/stances/{$id}/vote", 3, ['choice' => 1])[0]);
        $this->call('PATCH', "/api/stances/{$id}", 2, ['closed' => true]);
        $command = $this->app()->getContainer()->make(\ErnestDefoe\Stances\Console\TickCommand::class);
        $command->setLaravel($this->app()->getContainer());
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());

        [$status, $json] = $this->raw('GET', '/api/notifications', 3);
        $this->assertSame(200, $status, json_encode($json));
        $subject = collect($json['included'] ?? [])->firstWhere('type', 'stances');
        $this->assertNotNull($subject, json_encode($json));
        $this->assertEqualsCanonicalizing(['question', 'postId', 'discussionId'], array_keys($subject['attributes']));
    }

    // The independent review of the JSON:API change (2026-11-12).

    private function version(): int
    {
        return (int) $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->get('stances-needs-version', 0);
    }

    private function tags(): void
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
            ],
        ]);
    }

    #[Test]
    public function a_draft_for_a_discussion_needs_the_create_permission_in_its_tag()
    {
        $this->tags();
        // Granted before the first request: Flarum reads group permissions once per booted app.
        $this->prepareDatabase([
            User::class => [['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1]],
            'group_user' => [['user_id' => 5, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['permission' => 'tag2.viewForum', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'tag2.discussion.stances.create', 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
        $tag = fn (int $id) => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $id]]]];
        [$status, $json] = $this->call('POST', '/api/discussions', 1, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Open talk', 'content' => 'Hello there.'], 'relationships' => $tag(1)]]);
        $this->assertSame(201, $status, json_encode($json));
        $open = (int) $json['data']['id'];
        [$status, $json] = $this->call('POST', '/api/discussions', 1, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Staff talk', 'content' => 'Hello staff.'], 'relationships' => $tag(2)]]);
        $this->assertSame(201, $status, json_encode($json));
        $staff = (int) $json['data']['id'];

        $body = fn ($discussionId) => ['type' => 'agree', 'question' => 'Week 10?', 'labels' => ['Agree', 'Disagree'], 'discussionId' => $discussionId];
        $this->assertSame(201, $this->call('POST', '/api/stances', 3, $body($open))[0]);
        // Members can see the staff tag here but have no stances permission in it.
        $this->assertSame(403, $this->call('POST', '/api/stances', 3, $body($staff))[0]);
        // Not a number: the schema refuses it before anything else looks.
        [$status, $json] = $this->call('POST', '/api/stances', 3, $body('abc'));
        $this->assertSame(422, $status);
        $this->assertSame('/data/attributes/discussionId', $json['errors'][0]['source']['pointer']);
        // A discussion that doesn't exist: no discussion, so no permission.
        $this->assertSame(403, $this->call('POST', '/api/stances', 3, $body(999999))[0]);
        // With the permission in that tag, it's allowed.
        $this->assertSame(201, $this->call('POST', '/api/stances', 5, $body($staff))[0]);
    }

    #[Test]
    public function only_the_author_or_a_moderator_edits_closes_or_deletes_a_posted_block()
    {
        $id = $this->draft();
        $this->discussion("[stance={$id}]");

        $this->assertSame(403, $this->call('PATCH', "/api/stances/{$id}", 3, ['question' => 'Hijacked?', 'labels' => ['Agree', 'Disagree']])[0]);
        $this->assertSame(403, $this->call('PATCH', "/api/stances/{$id}", 3, ['closed' => true])[0]);
        $this->assertSame(403, $this->call('DELETE', "/api/stances/{$id}", 3)[0]);
        // Even the author can't delete a posted block (only a moderator can); it's still there.
        $this->assertSame(403, $this->call('DELETE', "/api/stances/{$id}", 2)[0]);
        [, $json] = $this->call('GET', "/api/stances/{$id}", 3);
        $this->assertSame('The trade deadline should move to week 10.', $json['data']['question']);
        $this->assertFalse($json['data']['closed']);
    }

    #[Test]
    public function asking_for_hidden_results_by_sparse_fields_still_gets_none()
    {
        [, $json] = $this->call('POST', '/api/stances', 2, ['type' => 'agree', 'question' => 'Week 10?', 'labels' => ['Agree', 'Disagree'], 'results' => 'voted']);
        $id = $json['data']['id'];
        $this->discussion("[stance={$id}]");
        $this->assertSame(200, $this->call('POST', "/api/stances/{$id}/vote", 4, ['choice' => 0])[0]);

        foreach ([3 => null, 4 => [1, 0]] as $as => $counts) {
            [$status, $json] = $this->raw('GET', "/api/stances/{$id}", $as, null, ['fields' => ['stances' => 'counts,average,resultsVisible']]);
            $this->assertSame(200, $status);
            $this->assertSame($counts, $json['data']['attributes']['counts'], "user {$as}");
            $this->assertEqualsCanonicalizing(['counts', 'average', 'resultsVisible'], array_keys($json['data']['attributes']));
        }
    }

    #[Test]
    public function locking_retagging_approving_and_hiding_a_discussion_refresh_every_count()
    {
        $this->tags();
        $this->extension('flarum-lock', 'flarum-flags', 'flarum-approval');
        $tag = fn (int $id) => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $id]]]];
        $id = $this->draft(1);
        [$status, $json] = $this->call('POST', '/api/discussions', 1, ['data' => ['type' => 'discussions', 'attributes' => ['title' => 'Owners meeting', 'content' => "[stance={$id}]"], 'relationships' => $tag(1)]]);
        $this->assertSame(201, $status, json_encode($json));
        $d = (int) $json['data']['id'];
        $patch = fn (array $attributes, array $relationships = []) => $this->call('PATCH', "/api/discussions/{$d}", 1, ['data' => ['type' => 'discussions', 'id' => (string) $d, 'attributes' => $attributes] + ($relationships ? ['relationships' => $relationships] : [])])[0];

        $this->assertSame([1, true], $this->needCount(3));

        $v = $this->version();
        $this->assertSame(200, $patch(['isLocked' => true]));
        $this->assertGreaterThan($v, $this->version(), 'lock');
        $this->assertSame([0, true], $this->needCount(3));
        $v = $this->version();
        $this->assertSame(200, $patch(['isLocked' => false]));
        $this->assertGreaterThan($v, $this->version(), 'unlock');
        $this->assertSame([1, true], $this->needCount(3));

        $v = $this->version();
        $this->assertSame(200, $patch([], $tag(2)));
        $this->assertGreaterThan($v, $this->version(), 'retag');
        $this->assertSame([0, true], $this->needCount(3), 'no stances permissions in the staff tag');
        $this->assertSame(200, $patch([], $tag(1)));
        $this->assertSame([1, true], $this->needCount(3));

        $v = $this->version();
        $post = \Flarum\Post\Post::query()->where('discussion_id', $d)->firstOrFail();
        $this->app()->getContainer()->make(\Illuminate\Contracts\Events\Dispatcher::class)
            ->dispatch(new \Flarum\Approval\Event\PostWasApproved($post, User::query()->findOrFail(1)));
        $this->assertGreaterThan($v, $this->version(), 'approval');

        $v = $this->version();
        $this->assertSame(200, $patch(['isHidden' => true]));
        $this->assertGreaterThan($v, $this->version(), 'discussion hidden');
        $this->assertSame([0, true], $this->needCount(3));
    }

    #[Test]
    public function a_last_page_of_exactly_twenty_has_no_next_link()
    {
        $page = function (int $offset) {
            [$status, $json] = $this->raw('GET', '/api/stances', 3, null, ['filter' => ['tab' => 'new'], 'page' => ['offset' => $offset]]);
            $this->assertSame(200, $status, json_encode($json));

            return [count($json['data']), isset($json['links']['next'])];
        };
        $post = function (int $blocks) {
            for ($n = 0; $n < $blocks; $n += 3) {
                $markers = [];
                for ($i = 0; $i < min(3, $blocks - $n); $i++) {
                    $markers[] = '[stance='.$this->draft(1).']';
                }
                $this->discussion(implode("\n\n", $markers), 1);
            }
        };

        $post(20);
        $this->assertSame([20, false], $page(0));

        $post(20);
        $this->assertSame([20, true], $page(0));
        $this->assertSame([20, false], $page(20));
        $this->assertSame([0, false], $page(40));
    }
}
