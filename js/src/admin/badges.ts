import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';

declare const flarum: any;

/**
 * Stances' two FoF Badges metrics in its trigger builder. The builder's list of metrics is fixed
 * in its own code, so they are added to it here; the server registers the metrics themselves
 * (src/Badges). Without fof/badges the module never loads and none of this runs.
 */
export default function registerBadges() {
  // Only the method it still has: if a later fof/badges renames it, nothing appears instead of throwing.
  flarum.reg.onLoad('fof-badges', 'admin/components/TriggerBuilder', (Builder: any) => {
    const proto = Builder?.prototype;
    if (!proto || typeof proto.getAvailableMetrics !== 'function') return;
    extend(proto, 'getAvailableMetrics', function (metrics: { value: string; label: string }[]) {
      if (!Array.isArray(metrics)) return;
      metrics.push(
        { value: 'stances_voted', label: app.translator.trans('ernestdefoe-stances.admin.badges.voted', {}, true) },
        { value: 'stances_created', label: app.translator.trans('ernestdefoe-stances.admin.badges.created', {}, true) }
      );
    });
  });
}
