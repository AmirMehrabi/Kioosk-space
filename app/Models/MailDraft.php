<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MailDraft extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'context', 'content'];

    protected $attributes = ['revision' => 0];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'revision' => 'integer'];
    }
}
