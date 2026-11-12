<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'discussion.stances.create' => Group::MEMBER_ID,
    'discussion.stances.vote' => Group::MEMBER_ID,
    'discussion.stances.viewVoters' => Group::MEMBER_ID,
    'discussion.stances.moderate' => Group::MODERATOR_ID,
]);
