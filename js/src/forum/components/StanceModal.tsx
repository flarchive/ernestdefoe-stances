import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { remember, t, type ResultsMode, type Stance, type StanceType } from '../util';
import { call, enabledTypes, ICONS, save } from './api';

declare const m: Mithril.Static;

export interface StanceModalAttrs extends IFormModalAttrs {
  /** Editing this block; a new draft otherwise. */
  stance?: Stance;
  /** The discussion being replied to, so the server can check this tag's permission up front. */
  discussionId?: number | null;
  onsave: (stance: Stance) => void;
}

const DEFAULT_LABELS: Record<StanceType, () => [string, string] | null> = {
  agree: () => [t('labels.agree'), t('labels.disagree')],
  yesno: () => [t('labels.yes'), t('labels.no')],
  pick: () => ['', ''],
  rating: () => null,
};

/** "Add a stance block" / "Edit stance block": type, statement, buttons, results, close date, options. */
export default class StanceModal extends FormModal<StanceModalAttrs> {
  type!: StanceType;
  question = '';
  labels: [string, string] = ['', ''];
  images: [{ path: string | null; url: string | null }, { path: string | null; url: string | null }] = [
    { path: null, url: null },
    { path: null, url: null },
  ];
  results!: ResultsMode;
  closesAt = '';
  allowChange = true;
  allowRemove = true;
  badges = true;
  anonymous = false;
  uploading: [boolean, boolean] = [false, false];

  oninit(vnode: Mithril.Vnode<StanceModalAttrs, this>) {
    super.oninit(vnode);
    const s = this.attrs.stance;
    const types = enabledTypes();

    this.type = s?.type ?? types[0] ?? 'agree';
    this.question = s?.question ?? '';
    this.labels = (s?.labels ?? DEFAULT_LABELS[this.type]() ?? ['', '']).slice() as [string, string];
    this.results = s?.results ?? ((app.forum.attribute('stancesResults') as ResultsMode) || 'voted');
    this.allowChange = s?.allowChange ?? app.forum.attribute('stancesAllowChange') !== false;
    this.allowRemove = s?.allowRemove ?? app.forum.attribute('stancesAllowRemove') !== false;
    this.badges = s?.badges ?? app.forum.attribute('stancesBadges') !== false;
    this.anonymous = s?.anonymous ?? false;
    this.closesAt = s?.closesAt ? toLocalInput(s.closesAt) : '';
    if (s?.images) {
      // Existing pictures: the server sends URLs; the path is the part after the assets folder.
      this.images = s.images.map((url) => ({ url, path: url ? 'stances/' + url.split('/stances/').pop() : null })) as any;
    }
  }

  className() {
    return 'StanceModal Modal--medium';
  }

  title() {
    return this.attrs.stance ? t('modal.edit_title') : t('modal.add_title');
  }

  content() {
    const s = this.attrs.stance;
    const locked = !!s && s.voteCount > 0;
    const sided = this.type !== 'rating';
    const canAnon = !s || s.postId === null;

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{t('modal.type')}</label>
            <div className="StanceModal-types" role="radiogroup" aria-label={t('modal.type')}>
              {enabledTypes().map((type) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={this.type === type ? 'true' : 'false'}
                  className={`StanceModal-type${this.type === type ? ' is-active' : ''}`}
                  disabled={locked}
                  onclick={() => this.setType(type)}
                >
                  <Icon name={ICONS[type]} />
                  <span>{t(`types.${type}`)}</span>
                </button>
              ))}
            </div>
          </div>

          <div className="Form-group">
            <label for="stance-question">{this.type === 'rating' ? t('modal.what_to_rate') : t('modal.question')}</label>
            <input
              id="stance-question"
              className="FormControl"
              name="question"
              maxlength={200}
              placeholder={t(`modal.placeholder_${this.type}`)}
              value={this.question}
              oninput={(e: InputEvent) => {
                this.question = (e.target as HTMLInputElement).value;
              }}
            />
          </div>

          {sided ? (
            <div className="StanceModal-labels">
              {[0, 1].map((i) => (
                <div className="Form-group">
                  <label for={`stance-label-${i}`}>{i === 0 ? t('modal.first') : t('modal.second')}</label>
                  <input
                    id={`stance-label-${i}`}
                    className="FormControl"
                    maxlength={40}
                    value={this.labels[i]}
                    placeholder={this.type === 'pick' ? t(i === 0 ? 'modal.this' : 'modal.that') : ''}
                    oninput={(e: InputEvent) => {
                      this.labels[i] = (e.target as HTMLInputElement).value;
                    }}
                  />
                  {this.type === 'pick' ? this.picture(i) : null}
                </div>
              ))}
            </div>
          ) : null}

          <div className="Form-group">
            <label id="stance-results-label">{t('modal.results')}</label>
            <div className="StanceModal-segments" role="radiogroup" aria-labelledby="stance-results-label">
              {(['always', 'voted', 'closed'] as ResultsMode[]).map((mode) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={this.results === mode ? 'true' : 'false'}
                  className={`StanceModal-segment${this.results === mode ? ' is-active' : ''}`}
                  onclick={() => (this.results = mode)}
                >
                  {t(`modal.results_${mode}`)}
                </button>
              ))}
            </div>
          </div>

          <div className="Form-group">
            <label for="stance-closes">{t('modal.closes')}</label>
            <input
              id="stance-closes"
              type="datetime-local"
              className="FormControl"
              value={this.closesAt}
              oninput={(e: InputEvent) => {
                this.closesAt = (e.target as HTMLInputElement).value;
              }}
            />
            <p className="helpText">{t('modal.closes_help')}</p>
          </div>

          <div className="Form-group StanceModal-checks">
            {this.check('allowChange', t('modal.allow_change'), t('modal.allow_change_help'))}
            {this.check('allowRemove', t('modal.allow_remove'), null, !this.allowChange)}
            {sided ? this.check('badges', t('modal.badges'), t('modal.badges_help'), this.anonymous) : null}
            {this.check('anonymous', t('modal.anonymous'), t('modal.anonymous_help'), !canAnon)}
          </div>

          <div className="Form-group StanceModal-actions">
            <Button type="button" className="Button" onclick={() => this.hide()}>
              {t('modal.cancel')}
            </Button>
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={this.uploading[0] || this.uploading[1]}>
              {this.attrs.stance ? t('modal.save') : t('modal.add')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  check(key: 'allowChange' | 'allowRemove' | 'badges' | 'anonymous', label: string, help: string | null, disabled = false) {
    const id = `stance-${key}`;
    return (
      <label className={`StanceModal-check${disabled ? ' is-disabled' : ''}`} for={id}>
        <input
          id={id}
          type="checkbox"
          checked={this[key] && !(disabled && key !== 'anonymous')}
          disabled={disabled}
          onchange={(e: Event) => {
            this[key] = (e.target as HTMLInputElement).checked;
          }}
        />
        <span>
          {label}
          {help ? <small>{help}</small> : null}
        </span>
      </label>
    );
  }

  picture(i: number) {
    const image = this.images[i];
    return (
      <div className="StanceModal-picture">
        {image.url ? <img src={image.url} alt="" /> : null}
        <label className="Button Button--flat StanceModal-upload">
          <Icon name="fas fa-image" /> {this.uploading[i] ? t('modal.uploading') : image.url ? t('modal.change_picture') : t('modal.add_picture')}
          <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="sr-only" onchange={(e: Event) => this.upload(i, e)} />
        </label>
        {image.url ? (
          <Button type="button" className="Button Button--link" onclick={() => (this.images[i] = { path: null, url: null })}>
            {t('modal.remove_picture')}
          </Button>
        ) : null}
      </div>
    );
  }

  upload(i: number, e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('image', file);
    this.uploading[i] = true;
    call<{ data: { path: string; url: string } }>('POST', '/stances/images', body)
      .then((r) => (this.images[i] = { path: r.data.path, url: r.data.url }))
      .catch(() => {})
      .finally(() => {
        this.uploading[i] = false;
        m.redraw();
      });
  }

  setType(type: StanceType) {
    const before = DEFAULT_LABELS[this.type]();
    const untouched = !before || (this.labels[0] === before[0] && this.labels[1] === before[1]) || (!this.labels[0] && !this.labels[1]);
    this.type = type;
    if (untouched) this.labels = (DEFAULT_LABELS[type]() ?? ['', '']).slice() as [string, string];
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    const data: Record<string, any> = {
      type: this.type,
      question: this.question,
      labels: this.type === 'rating' ? null : this.labels,
      images: this.type === 'pick' ? this.images.map((img) => img.path) : null,
      results: this.results,
      closesAt: this.closesAt ? new Date(this.closesAt).toISOString() : null,
      allowChange: this.allowChange,
      allowRemove: this.allowChange && this.allowRemove,
      badges: this.type !== 'rating' && !this.anonymous && this.badges,
      anonymous: this.anonymous,
    };
    if (!this.attrs.stance && this.attrs.discussionId) data.discussionId = this.attrs.discussionId;

    const s = this.attrs.stance;
    save(s ? s.id : null, data)
      .then((saved) => {
        remember(saved);
        this.hide();
        this.attrs.onsave(saved);
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}

/** An ISO time as the value a datetime-local input wants, in the viewer's own time zone. */
function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
