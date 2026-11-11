import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import type { Side, Stance } from '../util';

declare const m: Mithril.Static;

/**
 * After the viewer votes in the discussion's badge block, their own posts' badges change at once.
 * Pushing the attribute bumps each post's freshness, which is what lets its subtree redraw.
 */
export function refreshOwnSides(stance: Stance, before: Stance): void {
  const me = app.session.user;
  const discussion: any = app.store.getById('discussions', String(stance.discussionId));
  if (!me || discussion?.attribute('stanceBadge')?.id !== stance.id) return;

  // Results (and so everyone's sides) just became visible to this viewer: fetch the loaded posts
  // again, one request by id, so every badge appears.
  if (!before.resultsVisible && stance.resultsVisible) {
    const ids = app.store
      .all<any>('posts')
      .filter((post) => String(post.discussion?.()?.id?.()) === String(stance.discussionId))
      .map((post) => post.id());
    if (ids.length) app.store.find('posts', ids).then(() => m.redraw());
    return;
  }

  const tagOn = app.forum.attribute('stancesChangedTag') !== false;
  for (const post of app.store.all<any>('posts')) {
    if (post.user?.() !== me || String(post.discussion?.()?.id?.()) !== String(stance.discussionId)) continue;
    const old: Side | null = post.attribute('stanceSide');
    const posted = old ? (old.was ?? old.label) : null;
    const label = stance.mine === null ? null : (stance.labels?.[stance.mine] ?? null);
    const side: Side | null =
      stance.mine === null ? null : { block: stance.id, choice: stance.mine, label, was: tagOn && posted && posted !== label ? posted : null };
    post.pushAttributes({ stanceSide: side });
  }
}
