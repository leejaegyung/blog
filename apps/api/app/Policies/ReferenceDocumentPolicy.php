<?php

namespace App\Policies;

use App\Models\ReferenceDocument;
use App\Models\User;

class ReferenceDocumentPolicy
{
    public function update(User $user, ReferenceDocument $reference): bool
    {
        return $reference->project->user_id === $user->id;
    }

    public function delete(User $user, ReferenceDocument $reference): bool
    {
        return $reference->project->user_id === $user->id;
    }
}
