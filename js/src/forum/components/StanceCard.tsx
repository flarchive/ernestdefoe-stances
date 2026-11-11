import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/utils/humanTime';
import type Mithril from 'mithril';
import { cache, remember, t, type Stance } from '../util';
import { call, choiceLabel, ICONS } from './api';
import { explain } from './explain';
import StanceModal from './StanceModal';
import VotersModal from './VotersModal';
import { applySideFilter, sideFilter } from '../sides';
import { refreshOwnSides } from './ownSides';

declare const m: Mithril.Static;

export interface CardAttrs {
  stance: Stance;
  /** In its post, or featured above the discussion. */
  context: 'post' | 'featured';
  /** This block makes the discussion's badges: show the "Show replies from" filter. */
  isBadge?: boolean;
  /** For the featured copy: where the block lives. */
  onopen?: () => void;
}

/**
 * One block. Every copy (in its post, featured above the discussion, on the Stances page) reads
 * the shared cache, so a vote in one shows in all of them on the next redraw.
 */
export default class StanceCard extends Component<CardAttrs> {
  busy = false;

  stance(): Stance {
    return cache.get(this.attrs.stance.id) ?? this.attrs.stance;
  }

  view() {
    const s = this.stance();
    const typeLabel = t(`types.${s.type}`);

    return (
      <section
        className={`StanceCard StanceCard--${s.type}${this.attrs.context === 'featured' ? ' StanceCard--featured' : ''}`}
        aria-busy={this.busy ? 'true' : 'false'}
        aria-label={typeLabel}
      >
        <div className="StanceCard-head">
          <span className="StanceCard-kind">
            <Icon name={this.attrs.context === 'featured' ? 'fas fa-thumbtack' : ICONS[s.type]} />{' '}
            {this.attrs.context === 'featured' ? t('card.featured') : typeLabel}
          </span>
          {s.closed ? <span className="StanceCard-pill">{t('card.closed')}</span> : null}
          {s.hidden ? <span className="StanceCard-pill">{t('card.hidden')}</span> : null}
          {this.menu()}
        </div>
        <div className="StanceCard-question">{s.question}</div>
        {s.type === 'rating' ? this.rating() : s.type === 'pick' ? this.tiles() : this.bars()}
        {this.meta()}
        {this.attrs.isBadge && s.labels ? this.filter(s) : null}
      </section>
    );
  }

  /** Agree/Disagree and Yes/No: two buttons until results show, then bars you can still click to change. */
  bars() {
    const s = this.stance();
    const showBars = s.counts !== null;
    return (
      <div className={`StanceCard-choices${showBars ? ' StanceCard-choices--bars' : ''}`}>
        {[0, 1].map((choice) => {
          const label = choiceLabel(s, choice);
          const n = s.counts?.[choice] ?? 0;
          const pct = this.percent(n);
          const selected = s.mine === choice;
          return (
            <button
              type="button"
              className={`StanceChoice StanceChoice--${choice}${selected ? ' is-selected' : ''}`}
              aria-pressed={selected ? 'true' : 'false'}
              disabled={!this.clickable(choice)}
              onclick={() => this.vote(choice)}
              style={showBars ? { '--stance-fill': pct + '%' } : undefined}
            >
              {showBars ? <span className="StanceChoice-fill" aria-hidden="true" /> : null}
              <span className="StanceChoice-label">
                {selected ? <Icon name="fas fa-check" /> : null} {label}
              </span>
              {showBars ? <span className="StanceChoice-count">{t('card.percent', { percent: pct, count: n })}</span> : null}
            </button>
          );
        })}
      </div>
    );
  }

  /** This or That: two tiles, each with an optional picture. */
  tiles() {
    const s = this.stance();
    return (
      <div className="StanceCard-tiles">
        {[0, 1].map((choice) => {
          const selected = s.mine === choice;
          const image = s.images?.[choice];
          const n = s.counts?.[choice] ?? 0;
          return (
            <button
              type="button"
              className={`StanceTile${selected ? ' is-selected' : ''}`}
              aria-pressed={selected ? 'true' : 'false'}
              disabled={!this.clickable(choice)}
              onclick={() => this.vote(choice)}
            >
              {image ? <img className="StanceTile-image" src={image} alt="" loading="lazy" decoding="async" /> : null}
              <span className="StanceTile-label">
                <span className="StanceTile-name">
                  {selected ? <Icon name="fas fa-check" /> : null} {choiceLabel(s, choice)}
                </span>
                {s.counts ? <span className="StanceTile-count">{t('card.percent', { percent: this.percent(n), count: n })}</span> : null}
              </span>
              {s.counts ? <span className="StanceTile-bar" style={{ '--stance-fill': this.percent(n) + '%' }} aria-hidden="true" /> : null}
            </button>
          );
        })}
      </div>
    );
  }

  rating() {
    const s = this.stance();
    const max = Math.max(1, ...(s.counts ?? [0]));
    return (
      <div className="StanceCard-rating">
        <div className="StanceCard-ratingTop">
          <div className="StanceStars" role="group" aria-label={t('card.your_rating')}>
            {[1, 2, 3, 4, 5].map((star) => (
              <button
                type="button"
                className={`StanceStar${s.mine !== null && star <= s.mine ? ' is-on' : ''}`}
                aria-label={t('card.stars', { count: star })}
                aria-pressed={s.mine === star ? 'true' : 'false'}
                disabled={!this.clickable(star)}
                onclick={() => this.vote(star)}
              >
                <Icon name={s.mine !== null && star <= s.mine ? 'fas fa-star' : 'far fa-star'} />
              </button>
            ))}
          </div>
          {s.average !== null ? (
            <div className="StanceCard-average">
              <strong>{s.average.toFixed(1)}</strong>
              <span>{t('card.average_of', { count: s.voteCount })}</span>
            </div>
          ) : null}
        </div>
        {s.counts && s.voteCount ? (
          <div className="StanceHistogram">
            {[5, 4, 3, 2, 1].map((star) => {
              const n = s.counts![star - 1] ?? 0;
              return [
                <span className="StanceHistogram-label">{t('card.stars', { count: star })}</span>,
                <span className="StanceHistogram-bar" aria-hidden="true">
                  <i style={{ width: Math.round((n * 100) / max) + '%' }} />
                </span>,
                <span className="StanceHistogram-count">{n}</span>,
              ];
            })}
          </div>
        ) : null}
      </div>
    );
  }

  meta() {
    const s = this.stance();
    const items: Mithril.Children[] = [];

    if (s.mine !== null) {
      items.push(
        <span className="StanceCard-you">
          <Icon name="fas fa-check" /> {t('card.you_chose', { choice: choiceLabel(s, s.mine) })}
        </span>
      );
      if (s.allowChange && s.allowRemove && s.canVote) {
        items.push(
          <Button className="Button Button--link StanceCard-link" onclick={() => this.vote(null)}>
            {t('card.remove')}
          </Button>
        );
      }
      const discussion: any = app.store.getById('discussions', String(s.discussionId));
      if (s.postId && app.session.user && discussion?.canReply?.()) {
        items.push(
          <Button className="Button Button--link StanceCard-link" onclick={() => explain(s)}>
            {t('card.explain')}
          </Button>
        );
      }
    }

    if (s.canViewVoters && s.resultsVisible && s.voteCount > 0) {
      items.push(
        <Button className="Button Button--link StanceCard-link" onclick={() => app.modal.show(VotersModal, { stance: s })}>
          {t('card.voters')}
        </Button>
      );
    }

    items.push(<span>{t('card.votes', { count: s.voteCount })}</span>);

    if (!s.resultsVisible) items.push(<span>{s.results === 'closed' ? t('card.results_after_close') : t('card.results_after_vote')}</span>);
    if (s.closed) items.push(<span>{s.closedAt ? t('card.closed_on', { time: humanTime(s.closedAt) }) : t('card.closed')}</span>);
    else if (s.closesAt) items.push(<span>{t('card.closes', { time: humanTime(s.closesAt) })}</span>);
    if (s.anonymous) items.push(<span>{t('card.anonymous')}</span>);
    if (!s.allowChange && s.mine !== null) items.push(<span>{t('card.final')}</span>);
    if (!app.session.user && !s.closed) items.push(<span>{t('card.log_in')}</span>);

    if (this.attrs.context === 'featured' && this.attrs.onopen) {
      items.push(
        <Button className="Button Button--link StanceCard-link" onclick={this.attrs.onopen}>
          {t('card.go_to_post')}
        </Button>
      );
    }

    return <div className="StanceCard-meta">{items}</div>;
  }

  filter(s: Stance) {
    const current = sideFilter();
    const chip = (value: number | null, label: string) => (
      <button
        type="button"
        className={`StanceFilter-chip${current === value ? ' is-active' : ''}`}
        aria-pressed={current === value ? 'true' : 'false'}
        onclick={() => this.chooseSide(value)}
      >
        {label}
      </button>
    );
    return (
      <div className="StanceFilter" role="group" aria-label={t('filter.label')}>
        <span className="StanceFilter-label">{t('filter.label')}</span>
        {chip(null, t('filter.everyone'))}
        {chip(0, s.labels![0])}
        {chip(1, s.labels![1])}
      </div>
    );
  }

  menu() {
    const s = this.stance();
    const items: Mithril.Children[] = [];
    const action = (label: string, icon: string, run: () => Promise<any>) => (
      <Button icon={icon} onclick={() => this.run(run)}>
        {label}
      </Button>
    );

    if (s.canSteer && s.postId) {
      const discussion: any = app.store.getById('discussions', String(s.discussionId));
      const featured = discussion?.attribute('stanceFeatured')?.id === s.id;
      items.push(
        action(featured ? t('menu.unfeature') : t('menu.feature'), 'fas fa-thumbtack', () =>
          call('POST', `/stances/${s.id}/feature`, { on: !featured }).then(() => reloadDiscussion(s))
        )
      );
      const badge = discussion?.attribute('stanceBadge');
      if (s.type !== 'rating' && !s.anonymous && s.badges) {
        const picked = badge?.id === s.id && badge?.picked;
        items.push(
          action(picked ? t('menu.unbadge') : t('menu.badge'), 'fas fa-user-tag', () =>
            call('POST', `/stances/${s.id}/badge`, { on: !picked }).then(() => reloadDiscussion(s, true))
          )
        );
      }
    }
    if (s.canEdit && !s.closed) {
      items.push(
        <Button icon="fas fa-pencil-alt" onclick={() => app.modal.show(StanceModal, { stance: s, onsave: () => m.redraw() })}>
          {t('menu.edit')}
        </Button>
      );
    }
    if (s.canClose) {
      items.push(
        action(s.closed ? t('menu.reopen') : t('menu.close'), s.closed ? 'fas fa-lock-open' : 'fas fa-lock', () => this.patch({ closed: !s.closed }))
      );
      if (!s.anonymous) {
        items.push(
          action(t('menu.anonymous'), 'fas fa-user-secret', () =>
            confirm(t('menu.anonymous_confirm')) ? this.patch({ anonymous: true }) : Promise.resolve()
          )
        );
      }
    }
    if (s.canModerate && s.voteCount > 0) {
      items.push(
        action(t('menu.reset'), 'fas fa-rotate-left', () =>
          confirm(t('menu.reset_confirm', { count: s.voteCount }))
            ? call<{ data: Stance }>('POST', `/stances/${s.id}/reset`).then((r) => this.update(r.data))
            : Promise.resolve()
        )
      );
    }

    if (s.canModerate) {
      items.push(
        action(t('menu.delete'), 'fas fa-trash-alt', () =>
          confirm(t('menu.delete_confirm'))
            ? call('DELETE', `/stances/${s.id}`).then(() => {
                cache.delete(s.id);
                m.redraw();
              })
            : Promise.resolve()
        )
      );
    }

    if (!items.length) return null;

    return (
      <Dropdown
        className="StanceCard-menu"
        buttonClassName="Button Button--icon Button--flat"
        menuClassName="Dropdown-menu--right"
        icon="fas fa-ellipsis-h"
        label={t('menu.label')}
        accessibleToggleLabel={t('menu.label')}
        onshow={() => this.placeMenu()}
        onhide={() => this.unplaceMenu?.()}
      >
        {items}
      </Dropdown>
    );
  }

  /** Point the post stream at one side's replies (fetched), or back at the whole discussion. */
  chooseSide(value: number | null) {
    const s = this.stance();
    const discussion: any = app.store.getById('discussions', String(s.discussionId));
    if (!discussion || value === sideFilter()) return;
    if (value === null) return applySideFilter(discussion, null, null);
    this.run(() =>
      call<{ data: string[] }>('GET', `/stances/${s.id}/sides`, { choice: value }).then((r) => applySideFilter(discussion, value, r.data))
    );
  }

  unplaceMenu: (() => void) | null = null;

  /**
   * Core gives .Post-body `overflow: auto`, so a menu positioned inside the post is clipped. Pin it
   * to the viewport under the button (above it when there's no room below), and close it on scroll,
   * where a pinned menu would float away from its button.
   */
  placeMenu() {
    const root = this.element as HTMLElement | undefined;
    const toggle = root?.querySelector<HTMLElement>('.StanceCard-menu .Dropdown-toggle');
    const menu = root?.querySelector<HTMLElement>('.StanceCard-menu .Dropdown-menu');
    // Phones: core already shows menus as a fixed sheet at the bottom of the screen.
    if (!toggle || !menu || getComputedStyle(menu).position === 'fixed') return;
    const r = toggle.getBoundingClientRect();
    Object.assign(menu.style, { position: 'fixed', right: 'auto', bottom: 'auto', margin: '0', top: '0px', left: '0px' });
    const h = menu.offsetHeight;
    const w = menu.offsetWidth;
    const below = r.bottom + 4 + h <= window.innerHeight;
    menu.style.top = Math.max(4, below ? r.bottom + 4 : r.top - h - 4) + 'px';
    menu.style.left = Math.max(4, Math.min(r.right - w, window.innerWidth - w - 4)) + 'px';
    const close = () => {
      if (toggle.closest('.Dropdown')?.classList.contains('open')) toggle.click();
    };
    window.addEventListener('scroll', close, { once: true, passive: true });
    this.unplaceMenu = () => {
      window.removeEventListener('scroll', close);
      menu.removeAttribute('style');
      this.unplaceMenu = null;
    };
  }

  clickable(choice: number): boolean {
    const s = this.stance();
    if (this.busy || s.closed) return false;
    if (!app.session.user) return true; // opens the log-in dialog
    if (!s.canVote) return false;
    return s.mine === null || s.mine === choice || s.allowChange;
  }

  vote(choice: number | null) {
    const s = this.stance();
    if (!app.session.user) {
      app.modal.show(() => import('flarum/forum/components/LogInModal'));
      return;
    }
    if (choice !== null && choice === s.mine) return;
    this.run(() =>
      (choice === null
        ? call<{ data: Stance }>('DELETE', `/stances/${s.id}/vote`)
        : call<{ data: Stance }>('POST', `/stances/${s.id}/vote`, { choice })
      ).then((r) => {
        this.update(r.data);
        refreshOwnSides(r.data, s);
      })
    );
  }

  patch(data: Record<string, any>) {
    return call<{ data: Stance }>('PATCH', `/stances/${this.stance().id}`, data).then((r) => this.update(r.data));
  }

  update(stance: Stance) {
    remember(stance);
  }

  run(task: () => Promise<any>) {
    this.busy = true;
    m.redraw();
    task()
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  percent(n: number): number {
    const total = this.stance().voteCount;
    return total ? Math.round((n * 100) / total) : 0;
  }
}

/**
 * Featuring or picking the badge block changes the discussion's fields; picking also changes
 * every badge, so the posts already loaded are fetched again (one request, by id).
 */
function reloadDiscussion(s: Stance, posts = false) {
  return app.store.find('discussions', String(s.discussionId)).then(() => {
    if (!posts) return m.redraw();
    const ids = app.store
      .all<any>('posts')
      .filter((post) => String(post.discussion()?.id?.()) === String(s.discussionId))
      .map((post) => post.id());
    return (ids.length ? app.store.find('posts', ids) : Promise.resolve()).then(() => m.redraw());
  });
}
