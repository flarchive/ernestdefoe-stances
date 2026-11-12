import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import Notification from 'flarum/forum/components/Notification';
import CommentPost from 'flarum/forum/components/CommentPost';
import type Mithril from 'mithril';
import { remember, t, type Side, type Stance } from './util';
import { markerIds } from './composer';
import { clearSideFilter, hidesPost } from './sides';
import { featuredCopy } from './featured';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the route, the sidebar link,
 * the notification, the composer pill, the badge beside each reply, and the scan that mounts a
 * post's blocks into its markers. The card, the dialogs and the page are one chunk fetched the
 * first time something needs them.
 */

declare const m: Mithril.Static;

const ui = () => import('./components');

class MilestoneNotification extends Notification {
  icon() {
    return 'fas fa-scale-balanced';
  }
  href() {
    const subject: any = (this.attrs.notification as any).subject();
    const discussion: any = subject && app.store.getById('discussions', String(subject.attribute('discussionId')));
    return discussion ? app.route.discussion(discussion) : app.route('stances');
  }
  content() {
    return t('notification.milestone', { count: (this.attrs.notification as any).content()?.count ?? 0 });
  }
  excerpt() {
    return (this.attrs.notification as any).subject()?.attribute?.('question') ?? '';
  }
}

/** Mount the post's blocks into its `[stance=N]` holders. A holder with no block of this post stays empty. */
function scan(post: any, root: Element | null) {
  if (!root) return;
  const blocks: Stance[] = post.attribute('stanceBlocks') || [];
  const holders = root.querySelectorAll<HTMLElement>('.Post-body .StanceHolder[data-stance]');
  if (!blocks.length || !holders.length) return;

  const todo: Array<[HTMLElement, number]> = [];
  holders.forEach((holder) => {
    const id = Number(holder.dataset.stance);
    const block = blocks.find((b) => b.id === id);
    // Mounted already, unless something emptied the holder since.
    if (!block || (holder.dataset.mounted === String(id) && holder.childElementCount)) return;
    remember(block);
    holder.dataset.mounted = String(id);
    todo.push([holder, id]);
  });
  if (todo.length) ui().then(({ mountCard }) => todo.forEach(([holder, id]) => mountCard(holder, id)));
}

/** The composer pill: "+ Add stance", or how many blocks the text holds. Opens the list or the dialog. */
function composerPill(this: any, items: ItemList<Mithril.Children>, priority: number) {
  const composer = this.composer || this.attrs.composer;
  const discussion = this.attrs.discussion || this.attrs.post?.discussion?.();
  const allowed = discussion?.attribute('stanceCanCreate') ?? app.forum.attribute('stancesCanCreate');
  if (!app.session.user || !allowed) return;

  const count = markerIds(composer).length;
  items.add(
    'stances',
    <Button
      className="StancePill Button Button--ua-reset"
      onclick={() =>
        ui().then(({ ComposerModal, openAdd }) => {
          const attrs = composer.body.attrs;
          if (!count && !attrs.stances?.length && !attrs.post) openAdd(composer);
          else app.modal.show(ComposerModal, { composer });
        })
      }
    >
      <span className={`StancePill-label${count ? '' : ' StancePill-label--none'}`}>
        <Icon name="fas fa-scale-balanced" /> {count ? t('composer.pill_count', { count }) : t('composer.pill_add')}
      </span>
    </Button>,
    priority
  );
}

class ClosedNotification extends MilestoneNotification {
  icon() {
    return 'fas fa-lock';
  }
  content() {
    return t('notification.closed');
  }
}

app.initializers.add('ernestdefoe/stances', () => {
  app.routes['stances'] = {
    path: '/stances',
    component: () => ui().then(async (chunk) => (await chunk.loadCore(), { default: chunk.StancesPage })),
  };

  app.notificationComponents.stanceMilestone = MilestoneNotification;
  app.notificationComponents.stanceClosed = ClosedNotification;

  // String form: these live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (app.forum.attribute('stancesNav') === false) return;
    const count = Number(app.forum.attribute('stancesNeedVote') || 0);
    items.add(
      'stances',
      LinkButton.component({ href: app.route('stances'), icon: 'fas fa-scale-balanced' }, [
        t('nav'),
        count ? <span className="StancesNav-count">{count >= 100 ? '99+' : count}</span> : null,
      ]),
      74
    );
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('stanceMilestone', { name: 'stanceMilestone', icon: 'fas fa-scale-balanced', label: t('notification.preference') });
    if (app.forum.attribute('stancesClosedAlert')) {
      items.add('stanceClosed', { name: 'stanceClosed', icon: 'fas fa-lock', label: t('notification.closed_preference') });
    }
  });

  // Blocks into their markers. onupdate too: an edited post replaces its content.
  extend(CommentPost.prototype, ['oncreate', 'onupdate'], function (this: CommentPost) {
    scan(this.attrs.post, this.element);
  });

  // The badge: the side this member is on in the discussion's badge block, and a "changed" tag
  // when they were on the other side when they wrote this reply.
  extend(CommentPost.prototype, 'headerItems', function (this: CommentPost, items: ItemList<Mithril.Children>) {
    const side: Side | null = (this.attrs.post as any).attribute('stanceSide');
    if (!side || side.label === null) return;
    items.add(
      'stanceSide',
      [
        <span className={`StanceSide StanceSide--${side.choice}`}>
          <i className="StanceSide-dot" aria-hidden="true" />
          {side.label}
        </span>,
        side.was ? (
          <Tooltip text={t('badge.was', { side: side.was })}>
            <span className="StanceSide StanceSide--changed" tabindex="0" aria-label={t('badge.was', { side: side.was })}>
              {t('badge.changed')}
            </span>
          </Tooltip>
        ) : null,
      ],
      95
    );
  });

  // The featured block, in core's slot under the opening post. Not above it: core's scroll
  // anchoring needs the first post to be the stream's first child. A block that already sits in
  // the opening post isn't repeated.
  extend('flarum/forum/components/PostStream', 'afterFirstPostItems', function (this: any, items: ItemList<Mithril.Children>) {
    const featured: Stance | null = featuredCopy(this.discussion);
    if (!featured) return;
    remember(featured);
    items.add(
      'stanceFeatured',
      <div
        className="StanceFeatured"
        oncreate={(v: Mithril.VnodeDOM) => mountFeatured(v.dom, featured.id)}
        onupdate={(v: Mithril.VnodeDOM) => mountFeatured(v.dom, featured.id)}
      />
    );
  });

  // The filter belongs to one discussion: leaving it clears the filter.
  extend('flarum/forum/components/DiscussionPage', 'onremove', function () {
    clearSideFilter();
  });
  override('flarum/forum/states/PostStreamState', 'goToNumber', function (this: any, original: any, number: any, noAnimation?: boolean) {
    if (hidesPost(this.discussion, number)) {
      clearSideFilter();
      m.redraw();
    }
    return original(number, noAnimation);
  });

  // In the composer header, after the title (phones lift the first item into the top bar).
  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    composerPill.call(this, items, 1);
  });
  extend('flarum/forum/components/ReplyComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    composerPill.call(this, items, -6);
  });
  extend('flarum/forum/components/EditPostComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    composerPill.call(this, items, -6);
  });

  // A discussion with an open block the member hasn't voted on (admin setting, off by default).
  extend('flarum/forum/components/DiscussionListItem', 'infoItems', function (this: any, items: ItemList<Mithril.Children>) {
    if (!this.attrs.discussion.attribute('stancesNeedVote')) return;
    items.add(
      'stancesNeedVote',
      <span className="StancesNeedVote" title={t('list.needs_vote')}>
        <Icon name="fas fa-scale-balanced" /> <span className="sr-only">{t('list.needs_vote')}</span>
      </span>,
      5
    );
  });
});

function mountFeatured(host: Element, id: number) {
  const el = host as HTMLElement;
  if (el.dataset.mounted === String(id)) return;
  el.dataset.mounted = String(id);
  ui().then(({ mountCard }) => mountCard(host, id, 'featured'));
}
