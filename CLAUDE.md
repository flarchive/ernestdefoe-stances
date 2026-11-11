# Stances: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center session for review before release.

## Design rules that exist for a reason

- **A block is a draft until its post is saved with its `[stance=N]` marker** (`Listener\AttachBlocks`, on `Post\Event\Saving`). Only the author's OWN drafts attach; a marker for anything else stays a dead holder that shows nothing (quotes copy markers). Permission (per tag, `discussion.stances.create`) and the per-post limit are checked BEFORE the post is written and refused with a validation error, never by silently dropping blocks.
- **Taking a marker out hides the block (`hidden_at`), never deletes it**; putting it back restores it with its votes. Hidden blocks take no votes and are listed only for whoever may edit the post (`filter[post]`).
- **Votes are taken under a row lock** (`Votes`: SELECT … FOR UPDATE, re-read inside the transaction) and counts are RECOUNTED from `stance_votes`, never incremented (MySQL counters are unsigned).
- **Every vote, change and removal is appended to `stance_vote_log`**: badges compare the side a member is on now with the side they were on when they posted (strictly before the post's second, `Sides::side`). A reset clears the log with the votes.
- **The badge block** (`Sides::badgeBlocks`): the one the starter or a moderator picked; with the admin rule on `auto`, else the featured block, else the first sided block in the opening post. Never a Rating, an anonymous block, a block with badges off, or one in a hidden/private post: a badge must never reveal what the block doesn't.
- **Anonymous can be switched on later, never off** once the block is in a post. Anonymous blocks never list voters to anyone, staff included.
- **Results are left out on the server** until the viewer may see them (`Presenter`); the voters list also needs visible results.
- **Posts get `stanceBlocks` and `stanceSide` in a fixed number of queries per response** (`Api\PostBatch`: deferred field closures, state in a WeakMap keyed by the request). Blocks are only queried when some post's XML holds `<STANCE`. Keep it that way; never per-post queries.
- Discussion fields (`stanceFeatured`, `stanceBadge`, `stanceCanSteer`, `stanceCanCreate`) are on the discussion Show endpoint only. `stancesNeedVote` on lists only with the admin's icon setting, one query per page.
- **The featured block renders in core's `afterFirstPostItems` slot**, not above post 1: core's scroll anchoring (`scrollToItem`/`reScrollToTarget`) treats the first post as `:first-child`; anything before it makes the page jump to post 1. A featured block that already sits in the opening post isn't repeated.
- extend.php uses string literals for settings, never `Settings::KEY` at load time (autoload during a composer swap fatals every request).
- Frontend: forum.js only wires things up; the card, dialogs, voters list and page are ONE lazy chunk (`components/index.ts`), with static imports inside it (a dynamic import there would emit a second chunk). Blocks in posts are their own Mithril roots (`m.mount` into the formatter's `.StanceHolder`), because a post's subtree retainer never redraws children. Every copy of a block reads the shared `cache`.
- After the viewer votes in the badge block, their own posts get the new `stanceSide` via `pushAttributes` (bumps freshness so the retained subtree redraws).
- Composer pill: priority 1 in DiscussionComposer, -6 in ReplyComposer/EditPostComposer (the reply's title stays first). Spacing and vertical alignment are MEASURED against FoF Polls' "Add Poll" (labels share top and bottom). Extend lazy composers by module name.
- FoF Badges is touched only in `src/Badges`, whose metrics load only when fof/badges builds its MetricManager.

## Testing

- `composer test:setup && composer test` (SQLite by default). For MySQL/PostgreSQL set `DB_DRIVER` (and DB_HOST/PORT/DATABASE/USERNAME/PASSWORD, `DB_PREFIX=t_`) and check `vendor/flarum/testing/src/integration/tmp/config.php` says the driver before calling it a MySQL/PG run. `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum: ~/Herd/stancesdev (https://stancesdev.test, MySQL, prefix `sd_`; test logins in `.test-credentials` there). Screenshot + click-through scripts: ~/github/scopes/stances-shots (`node shots.mjs`, `node flow.mjs`, `W=390 node flow.mjs`).
