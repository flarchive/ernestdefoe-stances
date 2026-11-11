import app from 'flarum/admin/app';
import registerBadges from './badges';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe/stances', () => {
  registerBadges();
});
