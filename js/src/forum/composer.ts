import type { Stance } from './util';

/*
 * The composer's blocks live on composer.body.attrs, which lasts as long as the composer does
 * (minimised and reopened included). The text is the truth for what the post will show: each
 * block is a `[stance=N]` marker.
 */

const MARKER = /\[stance=(\d+)\]/gi;

export function composerStances(composer: any): Stance[] {
  const attrs = composer.body.attrs;
  return (attrs.stances ??= []);
}

export function markerIds(composer: any): number[] {
  const text: string = composer.fields?.content?.() ?? '';
  return [...text.matchAll(MARKER)].map((match) => Number(match[1]));
}

export function insertMarker(composer: any, id: number): void {
  const text: string = composer.fields?.content?.() ?? '';
  const [start] = composer.editor?.getSelectionRange?.() ?? [text.length];
  const before = text.slice(0, start);
  const lead = before === '' || before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
  composer.editor?.insertAtCursor(`${lead}[stance=${id}]\n\n`, false);
}

export function removeMarker(composer: any, id: number): void {
  const text: string = composer.fields?.content?.() ?? '';
  const match = new RegExp(`\\n?\\[stance=${id}\\]\\n?`, 'i').exec(text);
  if (match) composer.editor?.insertBetween(match.index, match.index + match[0].length, '', false);
}
