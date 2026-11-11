import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { remember, t, type Stance } from '../util';
import { call, ICONS } from './api';
import { composerStances, insertMarker, markerIds, removeMarker } from '../composer';
import StanceModal from './StanceModal';

declare const m: Mithril.Static;

export interface ComposerModalAttrs extends IInternalModalAttrs {
  composer: any;
}

/**
 * The composer pill's list: this post's blocks, whether each is in the text, and add, edit,
 * put back or take out. Taking a block out of the text hides it (its votes are kept); a draft
 * nobody posted is deleted.
 */
export default class ComposerModal extends Modal<ComposerModalAttrs> {
  loading = false;

  oninit(vnode: Mithril.Vnode<ComposerModalAttrs, this>) {
    super.oninit(vnode);
    const attrs = this.attrs.composer.body.attrs;
    // Editing a post: fetch its blocks once, hidden ones too.
    if (attrs.post && !attrs.stancesLoaded) {
      this.loading = true;
      call<{ data: Stance[] }>('GET', '/stances', { filter: { post: attrs.post.id() } })
        .then((r) => {
          const list = composerStances(this.attrs.composer);
          for (const s of r.data) if (!list.some((x) => x.id === s.id)) list.push(remember(s));
          attrs.stancesLoaded = true;
        })
        .catch(() => {})
        .finally(() => {
          this.loading = false;
          m.redraw();
        });
    }
  }

  className() {
    return 'StanceComposerModal Modal--small';
  }

  title() {
    return t('composer.list_title');
  }

  content() {
    const composer = this.attrs.composer;
    const list = composerStances(composer);
    const inText = markerIds(composer);
    const max = Number(app.forum.attribute('stancesMaxPerPost') || 3);
    const showing = list.filter((s) => inText.includes(s.id)).length;

    return (
      <div className="Modal-body">
        {this.loading ? <LoadingIndicator /> : null}
        {list.length ? (
          <ul className="StanceComposerModal-list">
            {list.map((s) => {
              const present = inText.includes(s.id);
              return (
                <li className="StanceComposerModal-item">
                  <Icon name={ICONS[s.type]} />
                  <div className="StanceComposerModal-text">
                    <span className="StanceComposerModal-question">{s.question}</span>
                    <span className="StanceComposerModal-status">
                      {present ? t('composer.in_text') : t('composer.not_in_text')}
                      {s.voteCount ? ' · ' + t('card.votes', { count: s.voteCount }) : ''}
                    </span>
                  </div>
                  <div className="StanceComposerModal-actions">
                    {s.canEdit && !s.closed ? (
                      <Button
                        className="Button Button--icon Button--flat"
                        icon="fas fa-pencil-alt"
                        aria-label={t('menu.edit')}
                        onclick={() => this.edit(s)}
                      />
                    ) : null}
                    {present ? (
                      <Button
                        className="Button Button--icon Button--flat"
                        icon="fas fa-eye-slash"
                        aria-label={t('composer.take_out')}
                        onclick={() => this.takeOut(s)}
                      />
                    ) : (
                      <Button
                        className="Button Button--icon Button--flat"
                        icon="fas fa-plus"
                        aria-label={t('composer.put_back')}
                        disabled={showing >= max}
                        onclick={() => {
                          insertMarker(composer, s.id);
                          m.redraw();
                        }}
                      />
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        ) : (
          <p className="StanceComposerModal-empty">{t('composer.empty')}</p>
        )}
        <p className="helpText">{t('composer.marker_help')}</p>
        <Button className="Button Button--primary Button--block" icon="fas fa-plus" disabled={showing >= max} onclick={() => this.add()}>
          {showing >= max ? t('composer.limit', { count: max }) : t('composer.add')}
        </Button>
      </div>
    );
  }

  add() {
    openAdd(this.attrs.composer);
  }

  edit(s: Stance) {
    const composer = this.attrs.composer;
    app.modal.show(StanceModal, {
      stance: s,
      onsave: (saved: Stance) => {
        const list = composerStances(composer);
        const i = list.findIndex((x) => x.id === saved.id);
        if (i >= 0) list[i] = saved;
        app.modal.show(ComposerModal, { composer });
      },
    });
  }

  takeOut(s: Stance) {
    const composer = this.attrs.composer;
    removeMarker(composer, s.id);
    if (s.postId === null) {
      // A draft no post has claimed: nothing to keep.
      call('DELETE', `/stances/${s.id}`).catch(() => {});
      const list = composerStances(composer);
      list.splice(list.indexOf(s), 1);
    }
    m.redraw();
  }
}

/** The dialog for a new block; saving puts its marker at the cursor. */
export function openAdd(composer: any): void {
  const discussion = composer.body.attrs.discussion || composer.body.attrs.post?.discussion?.();
  app.modal.show(StanceModal, {
    discussionId: discussion ? Number(discussion.id()) : null,
    onsave: (saved: Stance) => {
      composerStances(composer).push(saved);
      insertMarker(composer, saved.id);
    },
  });
}
