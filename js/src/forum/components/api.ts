import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { t, type Stance, type StanceType } from '../util';

/* Requests, errors and the type lists: only the lazy chunk needs these, so they stay out of forum.js. */

export const TYPES: StanceType[] = ['agree', 'yesno', 'pick', 'rating'];

export function enabledTypes(): StanceType[] {
  const list = String(app.forum.attribute('stancesTypes') ?? TYPES.join(',')).split(',');
  return TYPES.filter((type) => list.includes(type));
}

export const ICONS: Record<StanceType, string> = {
  agree: 'fas fa-thumbs-up',
  yesno: 'fas fa-circle-check',
  pick: 'fas fa-right-left',
  rating: 'fas fa-star',
};

/** The words for one choice: a button's label, or "4 stars". */
export function choiceLabel(stance: Stance, choice: number): string {
  return stance.type === 'rating' ? t('card.stars', { count: choice }) : (stance.labels?.[choice] ?? '');
}

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-stances.forum.errors.${code}`;
  if (code && extractText(app.translator.trans(key)) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return t('errors.generic');
}

/** Shows the error, then passes it on. */
function alert(error: any): never {
  app.alerts.show({ type: 'error' }, errorText(error));
  throw error;
}

/** A `stances` resource as the plain object the card reads. `type` is reserved in JSON:API, so the API says `blockType`. */
export function plain(model: any): Stance {
  const { blockType, ...attributes } = model.data.attributes;
  return { ...attributes, type: blockType, id: Number(model.id()) };
}

/** The store's record for a block, made on the spot for one that came inside a post. */
function record(id: number): any {
  return app.store.getById('stances', String(id)) ?? app.store.pushObject({ type: 'stances', id: String(id), attributes: {} });
}

/** Create (no id) or update a block through StanceResource. */
export function save(id: number | null, data: Record<string, any>): Promise<Stance> {
  const { type, ...attributes } = data;
  if (type !== undefined) attributes.blockType = type;
  const model = id ? record(id) : app.store.createRecord('stances');
  return model.save(attributes, { errorHandler: alert }).then(plain);
}

export function destroy(id: number): Promise<void> {
  return record(id).delete({}, { errorHandler: alert });
}

/** A page of blocks, and whether there's another. */
export function list(params: Record<string, any>): Promise<{ rows: Stance[]; more: boolean }> {
  return app.store.find<any[]>('stances', params, undefined, { errorHandler: alert } as any).then((models: any) => ({
    rows: models.map(plain),
    more: !!models.payload?.links?.next,
  }));
}

/** An action that answers with the block's document (vote, reset, feature, badge). */
export function act(method: 'POST' | 'DELETE', path: string, data?: Record<string, any>): Promise<Stance> {
  return call<any>(method, path, data).then((doc) => plain(app.store.pushPayload(doc)));
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, data?: Record<string, any> | FormData): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + path;
  const isForm = data instanceof FormData;
  return app.request<T>({
    method,
    url: method === 'GET' && data && !isForm ? url + '?' + query(data) : url,
    body: method === 'GET' ? undefined : data,
    serialize: isForm ? (raw: any) => raw : undefined,
    errorHandler: alert,
  } as any);
}

/** filter[tab]=new style parameters from a nested object. */
function query(data: Record<string, any>, prefix = ''): string {
  return Object.entries(data)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => {
      const name = prefix ? `${prefix}[${key}]` : key;
      return typeof value === 'object' ? query(value, name) : `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}`;
    })
    .join('&');
}
