import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';

/** The `stances` type, so a notification's subject can be stored. Only its question and place are read. */
export class StanceModel extends Model {
  question = Model.attribute<string>('question');
}

export default [new Extend.Store().add('stances', StanceModel)];
