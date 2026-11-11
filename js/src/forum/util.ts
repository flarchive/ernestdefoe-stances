import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export type StanceType = 'agree' | 'yesno' | 'pick' | 'rating';
export type ResultsMode = 'always' | 'voted' | 'closed';

export interface Stance {
  id: number;
  type: StanceType;
  question: string;
  labels: [string, string] | null;
  images: [string | null, string | null] | null;
  postId: number | null;
  discussionId: number | null;
  results: ResultsMode;
  allowChange: boolean;
  allowRemove: boolean;
  anonymous: boolean;
  badges: boolean;
  closesAt: string | null;
  closedAt: string | null;
  closed: boolean;
  hidden: boolean;
  voteCount: number;
  resultsVisible: boolean;
  /** Null while the viewer may not see results yet. Two entries, or five for Rating (1 to 5 stars). */
  counts: number[] | null;
  average: number | null;
  mine: number | null;
  canVote: boolean;
  canEdit: boolean;
  canModerate: boolean;
  canClose: boolean;
  canViewVoters: boolean;
  canSteer: boolean;
}

export interface Side {
  block: number;
  choice: number;
  label: string | null;
  /** The side they were on when they posted, if it was a different one. */
  was: string | null;
}

/** A translation as a plain string. */
export function t(key: string, params: Record<string, any> = {}): string {
  return extractText(app.translator.trans(`ernestdefoe-stances.forum.${key}`, params));
}

/** Every rendered copy of a block (in its post, featured above the discussion) reads from here. */
export const cache = new Map<number, Stance>();

export function remember(stance: Stance): Stance {
  cache.set(stance.id, stance);
  return stance;
}
