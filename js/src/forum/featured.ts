import type { Stance } from './util';

/** The featured block, when it lives in a reply (one in the opening post is already right there). */
export function featuredCopy(discussion: any): Stance | null {
  const featured: Stance | null = discussion?.attribute('stanceFeatured') ?? null;
  const firstPostId = discussion?.data?.relationships?.firstPost?.data?.id;
  return featured && String(featured.postId) !== String(firstPostId) ? featured : null;
}
