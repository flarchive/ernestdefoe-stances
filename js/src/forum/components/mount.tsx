import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import StanceCard from './StanceCard';
import { cache, type Stance } from '../util';
import { featuredCopy } from '../featured';

declare const m: Mithril.Static;

/**
 * Blocks inside a post are their own Mithril roots (m.mount), not children of the post: the
 * post's subtree retainer skips redraws until the POST changes, so a child card would never
 * show a new vote. A root is redrawn by every m.redraw(), and unmounts itself once the post has
 * replaced the element it lives in. The holder is the formatter's own element, so the post body's
 * top-level nodes (which m.trust counts) never change.
 */
class Holder extends Component<{ id: number; host: Element; context: 'post' | 'featured' }> {
  view() {
    const host = this.attrs.host;
    if (!host.isConnected) {
      // Only once it is gone for good: some themes lift the post body out and put it back while
      // laying the post out, and unmounting then would leave the block empty.
      window.setTimeout(() => {
        if (!host.isConnected) m.mount(host, null);
      }, 0);
      return null;
    }
    const stance = cache.get(this.attrs.id);
    if (!stance) return null;

    const discussion: any = stance.discussionId ? app.store.getById('discussions', String(stance.discussionId)) : null;
    const isBadge = discussion?.attribute('stanceBadge')?.id === stance.id;
    const featured = this.attrs.context === 'featured';

    return (
      <StanceCard
        stance={stance}
        context={this.attrs.context}
        // The filter sits on one copy only: the featured copy when there is one.
        isBadge={isBadge && (featured || featuredCopy(discussion)?.id !== stance.id)}
        onopen={featured ? () => goToPost(stance) : undefined}
      />
    );
  }
}

export function mountCard(host: Element, id: number, context: 'post' | 'featured' = 'post'): void {
  m.mount(host, { view: () => <Holder id={id} host={host} context={context} /> });
}

function goToPost(stance: Stance) {
  const post: any = stance.postId ? app.store.getById('posts', String(stance.postId)) : null;
  const discussion: any = app.store.getById('discussions', String(stance.discussionId));
  if (discussion) m.route.set(app.route.discussion(discussion, post?.number?.() ?? 1));
}
