<?php

namespace ErnestDefoe\Stances;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $block_id
 * @property int $user_id
 * @property int $choice
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Vote extends AbstractModel
{
    protected $table = 'stance_votes';

    public $timestamps = true;

    protected $casts = ['choice' => 'integer'];
}
