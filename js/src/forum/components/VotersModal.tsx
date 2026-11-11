import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { t, type Stance } from '../util';
import { call, choiceLabel } from './api';

declare const m: Mithril.Static;

interface Voter {
  choice: number;
  user: { id: number; username: string; displayName: string; avatarUrl: string | null; slug: string };
}

/** Who chose what, filtered by choice, 50 at a time. */
export default class VotersModal extends Modal<IInternalModalAttrs & { stance: Stance }> {
  choice: number | null = null;
  rows: Voter[] = [];
  next: number | null = 0;
  busy = false;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    this.more();
  }

  className() {
    return 'StanceVotersModal Modal--small';
  }

  title() {
    return t('voters.title');
  }

  content() {
    const s = this.attrs.stance;
    const choices = s.type === 'rating' ? [5, 4, 3, 2, 1] : [0, 1];

    return (
      <div className="Modal-body">
        <div className="StanceFilter" role="group" aria-label={t('voters.show')}>
          {[null, ...choices].map((choice) => (
            <button
              type="button"
              className={`StanceFilter-chip${this.choice === choice ? ' is-active' : ''}`}
              aria-pressed={this.choice === choice ? 'true' : 'false'}
              onclick={() => this.pick(choice)}
            >
              {choice === null ? t('filter.everyone') : choiceLabel(s, choice)}
            </button>
          ))}
        </div>
        <ul className="StanceVoters">
          {this.rows.map((row) => (
            <li className="StanceVoters-row">
              <Link href={app.route('user', { username: row.user.slug })} className="StanceVoters-user" onclick={() => this.hide()}>
                {row.user.avatarUrl ? (
                  <img className="Avatar" src={row.user.avatarUrl} alt="" loading="lazy" />
                ) : (
                  <span className="Avatar">{row.user.displayName.charAt(0).toUpperCase()}</span>
                )}
                <span className="StanceVoters-name">{row.user.displayName}</span>
              </Link>
              <span className={`StanceSide StanceSide--${s.type === 'rating' ? 'n' : row.choice}`}>{choiceLabel(s, row.choice)}</span>
            </li>
          ))}
        </ul>
        {this.busy ? <LoadingIndicator /> : null}
        {!this.busy && !this.rows.length ? <p className="StanceVoters-empty">{t('voters.none')}</p> : null}
        {!this.busy && this.next !== null && this.rows.length ? (
          <Button className="Button Button--block" onclick={() => this.more()}>
            {t('voters.more')}
          </Button>
        ) : null}
      </div>
    );
  }

  pick(choice: number | null) {
    this.choice = choice;
    this.rows = [];
    this.next = 0;
    this.more();
  }

  more() {
    if (this.next === null) return;
    this.busy = true;
    const choice = this.choice;
    call<{ data: Voter[]; links: { next?: number } }>('GET', `/stances/${this.attrs.stance.id}/voters`, { choice, page: { offset: this.next } })
      .then((r) => {
        if (choice !== this.choice) return;
        this.rows.push(...r.data);
        this.next = r.links?.next ?? null;
      })
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
