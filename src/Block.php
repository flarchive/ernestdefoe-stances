<?php

namespace ErnestDefoe\Stances;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block inside a post: a statement members answer with one click.
 *
 * @property int $id
 * @property int|null $post_id
 * @property int|null $discussion_id
 * @property int|null $user_id
 * @property string $type
 * @property string $question
 * @property array<int, string>|null $labels
 * @property array<int, string|null>|null $images
 * @property string $results
 * @property bool $allow_change
 * @property bool $allow_remove
 * @property bool $anonymous
 * @property bool $badges
 * @property Carbon|null $closes_at
 * @property Carbon|null $closed_at
 * @property int $vote_count
 * @property array<int, int>|null $counts
 * @property Carbon|null $hidden_at
 * @property Carbon|null $closed_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Post|null $post
 * @property Discussion|null $discussion
 * @property User|null $user
 */
class Block extends AbstractModel
{
    // Visibility is registered with Flarum (Scope\BlockVisibility), so every path that asks
    // (our endpoints, the notifications list, other extensions) gets the same answer.
    use ScopeVisibilityTrait;

    public const AGREE = 'agree';
    public const YESNO = 'yesno';
    public const PICK = 'pick';
    public const RATING = 'rating';
    public const TYPES = [self::AGREE, self::YESNO, self::PICK, self::RATING];
    /** The types that have sides, so can make stance badges. */
    public const SIDED = [self::AGREE, self::YESNO, self::PICK];

    public const SHOW_ALWAYS = 'always';
    public const SHOW_VOTED = 'voted';
    public const SHOW_CLOSED = 'closed';
    public const RESULTS = [self::SHOW_ALWAYS, self::SHOW_VOTED, self::SHOW_CLOSED];

    protected $table = 'stance_blocks';

    public $timestamps = true;

    protected $casts = [
        'labels' => 'array',
        'images' => 'array',
        'counts' => 'array',
        'allow_change' => 'boolean',
        'allow_remove' => 'boolean',
        'anonymous' => 'boolean',
        'badges' => 'boolean',
        'vote_count' => 'integer',
        'closes_at' => 'datetime',
        'closed_at' => 'datetime',
        'hidden_at' => 'datetime',
        'closed_notified_at' => 'datetime',
    ];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null || ($this->closes_at !== null && $this->closes_at->isPast());
    }

    public function isSided(): bool
    {
        return in_array($this->type, self::SIDED, true);
    }

    /** Can make stance badges: sided, public, switched on and showing in its post. */
    public function makesBadges(): bool
    {
        return $this->isSided() && ! $this->anonymous && $this->badges && $this->hidden_at === null && $this->post_id !== null;
    }

    public function minChoice(): int
    {
        return $this->type === self::RATING ? 1 : 0;
    }

    public function maxChoice(): int
    {
        return $this->type === self::RATING ? 5 : 1;
    }

    /** The words for a choice: a button label, or "4 stars". */
    public function label(?int $choice): ?string
    {
        if ($choice === null) {
            return null;
        }

        return $this->type === self::RATING ? (string) $choice : ($this->labels[$choice] ?? null);
    }
}
