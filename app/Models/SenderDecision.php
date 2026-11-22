<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'address', 'destination', 'revision', 'previous_destination', 'return_revision', 'return_destination', 'needs_sync', 'reviewed_at'])]
class SenderDecision extends Model
{
    protected function casts(): array
    {
        return ['needs_sync' => 'boolean', 'reviewed_at' => 'datetime'];
    }
}
