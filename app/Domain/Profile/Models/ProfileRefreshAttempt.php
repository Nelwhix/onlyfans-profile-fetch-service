<?php

namespace App\Domain\Profile\Models;

use App\Domain\Profile\Enums\RefreshOutcome;
use Database\Factories\ProfileRefreshAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
#[UseFactory(ProfileRefreshAttemptFactory::class)]
class ProfileRefreshAttempt extends Model
{
    /** @use HasFactory<ProfileRefreshAttemptFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'outcome' => RefreshOutcome::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Profile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
