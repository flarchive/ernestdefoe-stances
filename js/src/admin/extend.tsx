import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-stances.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-stances.${key}`;

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('type_agree'), type: 'boolean', label: t('types.agree'), help: t('settings.types_help') }), 114)
    .setting(() => ({ setting: setting('type_yesno'), type: 'boolean', label: t('types.yesno') }), 113)
    .setting(() => ({ setting: setting('type_pick'), type: 'boolean', label: t('types.pick') }), 112)
    .setting(() => ({ setting: setting('type_rating'), type: 'boolean', label: t('types.rating') }), 111)
    .setting(() => ({ setting: setting('max_per_post'), type: 'number', min: 1, max: 10, label: t('settings.max_per_post') }), 100)
    .setting(
      () => ({
        setting: setting('results'),
        type: 'select',
        options: { always: t('settings.results_always'), voted: t('settings.results_voted'), closed: t('settings.results_closed') },
        default: 'voted',
        label: t('settings.results'),
        help: t('settings.results_help'),
      }),
      90
    )
    .setting(() => ({ setting: setting('allow_change'), type: 'boolean', label: t('settings.allow_change') }), 80)
    .setting(() => ({ setting: setting('allow_remove'), type: 'boolean', label: t('settings.allow_remove') }), 79)
    .setting(() => ({ setting: setting('badges'), type: 'boolean', label: t('settings.badges'), help: t('settings.badges_help') }), 70)
    .setting(
      () => ({
        setting: setting('badge_rule'),
        type: 'select',
        options: { auto: t('settings.badge_rule_auto'), picked: t('settings.badge_rule_picked'), off: t('settings.badge_rule_off') },
        default: 'auto',
        label: t('settings.badge_rule'),
        help: t('settings.badge_rule_help'),
      }),
      69
    )
    .setting(() => ({ setting: setting('changed_tag'), type: 'boolean', label: t('settings.changed_tag'), help: t('settings.changed_tag_help') }), 68)
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 60)
    .setting(() => ({ setting: setting('list_icon'), type: 'boolean', label: t('settings.list_icon'), help: t('settings.list_icon_help') }), 59)
    .setting(
      () => ({ setting: setting('closed_alert'), type: 'boolean', label: t('settings.closed_alert'), help: t('settings.closed_alert_help') }),
      58
    )
    .permission(() => ({ permission: 'discussion.stances.vote', icon: 'fas fa-scale-balanced', label: t('permissions.vote') }), 'reply', 55)
    .permission(() => ({ permission: 'discussion.stances.viewVoters', icon: 'fas fa-users', label: t('permissions.view_voters') }), 'view', 55)
    .permission(() => ({ permission: 'discussion.stances.create', icon: 'fas fa-scale-balanced', label: t('permissions.create') }), 'start', 55)
    .permission(
      () => ({ permission: 'discussion.stances.moderate', icon: 'fas fa-scale-balanced', label: t('permissions.moderate') }),
      'moderate',
      55
    ),
];
