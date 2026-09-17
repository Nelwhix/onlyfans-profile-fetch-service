<?php

namespace App\Domain\Profile\Models;

use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $username
 * @property int|null $likes
 * @property int|null $revision
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_failure_reason
 * @property Carbon|null $next_refresh_due_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Unguarded]
#[UseFactory(ProfileFactory::class)]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    use HasUlids;

    protected function casts(): array
    {
        return [
            'last_attempted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'next_refresh_due_at' => 'datetime',
            'likes' => 'integer',
            'revision' => 'integer',
        ];
    }
}
