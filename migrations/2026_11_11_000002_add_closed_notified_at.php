<?php

use Flarum\Database\Migration;

// When voters were told this block closed (the optional "voting closed" alert), so they're told once.
return Migration::addColumns('stance_blocks', [
    'closed_notified_at' => ['dateTime', 'nullable' => true],
]);
