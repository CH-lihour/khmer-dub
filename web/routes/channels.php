<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Live progress of a user's videos (App\Events\VideoJobUpdated): only that user may listen.
Broadcast::channel('users.{id}', fn (User $user, int $id) => $user->id === $id);
