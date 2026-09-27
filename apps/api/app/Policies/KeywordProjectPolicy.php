<?php

namespace App\Policies;

use App\Models\KeywordProject;
use App\Models\User;

class KeywordProjectPolicy
{
    public function view(User $user, KeywordProject $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, KeywordProject $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function delete(User $user, KeywordProject $project): bool
    {
        return $project->user_id === $user->id;
    }
}
