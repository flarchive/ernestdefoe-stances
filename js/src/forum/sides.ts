import app from 'flarum/forum/app';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/*
 * The "Show replies from" filter. It points the discussion's post stream at the post ids the
 * server returns for one side (core's stream reads discussion.postIds() every time), so a long
 * discussion pages through that side alone. forum.js needs it to clear the filter when the
 * discussion page goes away; the card in the lazy chunk fetches the ids and applies them.
 */

let current: number | null = null;
let filtered: any = null;
let allowed: Set<string> | null = null;

const allIds = (discussion: any): string[] => Object.getPrototypeOf(discussion).postIds.call(discussion);

export function sideFilter(): number | null {
  return current;
}

/** ids null puts the whole discussion back. */
export function applySideFilter(discussion: any, value: number | null, ids: string[] | null): void {
  if (filtered && filtered !== discussion) delete filtered.postIds;
  current = value;
  if (ids) {
    // Posts that arrive later (your own reply, someone else's) stay in: only the ones known now are filtered.
    const known = new Set(allIds(discussion));
    allowed = new Set(ids);
    discussion.postIds = () => allIds(discussion).filter((id) => allowed!.has(id) || !known.has(id));
    filtered = discussion;
  } else {
    delete discussion.postIds;
    filtered = null;
    allowed = null;
  }
  const stream: any = app.current.get('stream');
  if (stream?.discussion === discussion) stream.goToFirst();
  m.redraw();
}

export function clearSideFilter(): void {
  if (filtered) delete filtered.postIds;
  filtered = null;
  allowed = null;
  current = null;
}

/** Jumping to a post the filter hides (a link, a notification, the scrubber): show everyone again first. */
export function hidesPost(discussion: any, number: number | string): boolean {
  if (!allowed || filtered !== discussion || number === 'reply') return false;
  const post = app.store.all<any>('posts').find((p) => String(p.discussion?.()?.id?.()) === String(discussion.id()) && p.number() === Number(number));
  return !post || !allowed.has(String(post.id()));
}
