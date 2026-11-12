import app from 'flarum/forum/app';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import { t, type Stance } from '../util';
import { choiceLabel } from './api';

/**
 * "Explain my vote": a reply that mentions the block's post (its author is notified and the
 * quote card shows) and says what you chose.
 */
export function explain(s: Stance): void {
  const discussion: any = app.store.getById('discussions', String(s.discussionId));
  if (!discussion || s.mine === null) return;

  const post: any = s.postId ? app.store.getById('posts', String(s.postId)) : null;
  const author = post?.user?.();
  const mention = author && 'flarum-mentions' in (flarum.extensions || {}) ? `@"${author.displayName()}"#p${s.postId} ` : '';
  const text = mention + t('explain.text', { choice: choiceLabel(s, s.mine), question: s.question }) + '\n\n';

  Promise.resolve(DiscussionControls.replyAction.call(discussion, true, false)).then(() =>
    app.composer.editorReady().then(() => app.composer.editor?.insertAtCursor(text, false))
  );
}

declare const flarum: { extensions: Record<string, unknown> };
