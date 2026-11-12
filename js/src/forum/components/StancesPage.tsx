import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import humanTime from 'flarum/common/utils/humanTime';
import type Mithril from 'mithril';
import StanceCard from './StanceCard';
import { cache, remember, t, type Stance } from '../util';
import { choiceLabel, enabledTypes, ICONS, list } from './api';

declare const m: Mithril.Static;
declare const flarum: any;

type Tab = 'needs' | 'trending' | 'new' | 'closing';

interface Row extends Stance {
  discussion: { id: number; title: string; slug: string } | null;
  postNumber: number | null;
  author: { username: string; displayName: string } | null;
  votesToday: number;
}

/** Core components this page builds on, loaded through the registry: some live in lazy chunks. */
let PageStructure: any;
let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

/**
 * /stances: blocks from across the forum, in tabs: Needs your vote (members), Trending, New and
 * Closing soon, with a type filter. Built in core's page structure with the forum's own sidebar,
 * so any theme dresses it. A row opens its block right there to vote.
 */
export default class StancesPage extends Page {
  tab: Tab = app.session.user ? 'needs' : 'trending';
  type = '';
  rows: Row[] = [];
  next: number | null = null;
  loading = true;
  open: number | null = null;
  private ticket = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const tab = m.route.param('tab') as Tab;
    if (['needs', 'trending', 'new', 'closing'].includes(tab) && (tab !== 'needs' || app.session.user)) this.tab = tab;
    app.history.push('stances', t('page.title'));
    this.bodyClass = 'App--stances';
    this.load(0);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(t('page.title'));
    app.setTitleCount(0);
  }

  load(offset: number) {
    const ticket = ++this.ticket;
    this.loading = true;
    list({ filter: { tab: this.tab, type: this.type }, page: { offset } })
      .then((r) => {
        if (ticket !== this.ticket) return;
        const rows = r.rows as Row[];
        rows.forEach((row) => remember(row));
        this.rows = offset ? this.rows.concat(rows) : rows;
        this.next = r.more ? offset + rows.length : null;
      })
      .catch(() => {})
      .finally(() => {
        if (ticket === this.ticket) this.loading = false;
        m.redraw();
      });
  }

  switchTab(tab: Tab) {
    if (tab === this.tab) return;
    this.tab = tab;
    this.rows = [];
    this.open = null;
    window.history.replaceState(window.history.state, '', app.route('stances') + '?tab=' + tab);
    this.load(0);
  }

  view() {
    const tabs: Tab[] = app.session.user ? ['needs', 'trending', 'new', 'closing'] : ['trending', 'new', 'closing'];
    const types: Record<string, string> = { '': t('page.all_types') };
    enabledTypes().forEach((type) => (types[type] = t(`types.${type}`)));

    return (
      <PageStructure className="StancesPage" sidebar={() => <IndexSidebar />}>
        <div className="StancesPage-header">
          <h2 className="StancesPage-title">
            <Icon name="fas fa-scale-balanced" /> {t('page.title')}
          </h2>
          <p className="StancesPage-lead">{t('page.lead')}</p>
        </div>

        <div className="StancesPage-toolbar">
          <nav className="StancesPage-tabs" role="tablist" aria-label={t('page.title')}>
            {tabs.map((tab) => (
              <button
                type="button"
                role="tab"
                aria-selected={this.tab === tab ? 'true' : 'false'}
                className={`StancesPage-tab${this.tab === tab ? ' is-active' : ''}`}
                onclick={() => this.switchTab(tab)}
              >
                {t(`page.tabs.${tab}`)}
                {tab === 'needs' && Number(app.forum.attribute('stancesNeedVote')) > 0 ? (
                  <span className="StancesPage-count">{app.forum.attribute('stancesNeedVote')}</span>
                ) : null}
              </button>
            ))}
          </nav>
          <label className="StancesPage-type">
            <span className="sr-only">{t('page.type')}</span>
            <Select
              options={types}
              value={this.type}
              onchange={(value: string) => {
                this.type = value;
                this.open = null;
                this.load(0);
              }}
            />
          </label>
        </div>

        <ul className="StancesPage-list">{this.rows.map((row) => this.row(row))}</ul>

        {this.loading ? <LoadingIndicator /> : null}
        {!this.loading && !this.rows.length ? <p className="StancesPage-empty">{t(`page.empty.${this.tab}`)}</p> : null}
        {!this.loading && this.next !== null ? (
          <Button className="Button StancesPage-more" onclick={() => this.load(this.next!)}>
            {t('page.more')}
          </Button>
        ) : null}
        {this.tab === 'trending' ? <p className="StancesPage-note">{t('page.trending_note')}</p> : null}
      </PageStructure>
    );
  }

  row(row: Row) {
    const s = cache.get(row.id) ?? row;
    // Core's route by id and slug: a stand-in model would break extensions that override
    // app.route.discussion and read more of the model (seen on the showcase: .tags()).
    const href = row.discussion
      ? app.route('discussion.near', { id: row.discussion.id + (row.discussion.slug ? '-' + row.discussion.slug : ''), near: row.postNumber ?? 1 })
      : '#';
    const open = this.open === row.id;

    return (
      <li className={`StancesPage-row${open ? ' is-open' : ''}`} key={row.id}>
        <div className="StancesPage-rowMain">
          <span className="StancesPage-icon" aria-hidden="true">
            <Icon name={ICONS[s.type]} />
          </span>
          <div className="StancesPage-text">
            <Link className="StancesPage-question" href={href}>
              {s.question}
            </Link>
            {this.tab !== 'needs' && s.mine === null && s.canVote ? <span className="StancesPage-needs">{t('page.needs_badge')}</span> : null}
            <div className="StancesPage-sub">
              {[
                t(`types.${s.type}`),
                row.discussion ? t('page.in', { title: row.discussion.title }) : null,
                row.author?.displayName,
                s.closed ? t('card.closed') : s.closesAt ? t('card.closes', { time: humanTime(s.closesAt) }) : null,
              ]
                .filter(Boolean)
                .join(' · ')}
            </div>
          </div>
          <div className="StancesPage-leading">{this.leading(s)}</div>
          <div className="StancesPage-votes">
            <strong>{s.voteCount}</strong>
            <span>{t('page.today', { count: row.votesToday })}</span>
          </div>
          <Button className="Button StancesPage-toggle" aria-expanded={open ? 'true' : 'false'} onclick={() => (this.open = open ? null : row.id)}>
            {open ? t('page.hide') : s.canVote && s.mine === null ? t('page.vote') : t('page.view')}
          </Button>
        </div>
        {open ? (
          <div className="StancesPage-card">
            <StanceCard stance={s} context="post" />
          </div>
        ) : null}
      </li>
    );
  }

  leading(s: Stance) {
    if (!s.counts)
      return (
        <span className="StancesPage-hiddenResults">{s.results === 'closed' ? t('card.results_after_close') : t('card.results_after_vote')}</span>
      );
    if (s.type === 'rating')
      return (
        <span className="StancesPage-lead-text">{s.average !== null ? t('page.rating', { average: s.average.toFixed(1) }) : t('page.no_votes')}</span>
      );
    if (!s.voteCount) return <span className="StancesPage-lead-text">{t('page.no_votes')}</span>;
    const a = Math.round((s.counts[0] * 100) / s.voteCount);
    const lead = s.counts[0] === s.counts[1] ? null : s.counts[0] > s.counts[1] ? 0 : 1;
    return [
      <span className={`StancesPage-mini StancesPage-mini--${s.type}`} aria-hidden="true">
        <i style={{ width: a + '%' }} />
      </span>,
      <span className="StancesPage-lead-text">
        {lead === null ? t('page.tied') : t('page.leads', { choice: choiceLabel(s, lead), percent: lead === 0 ? a : 100 - a })}
      </span>,
    ];
  }
}
