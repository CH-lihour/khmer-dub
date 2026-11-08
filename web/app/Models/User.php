<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The upload form's choices until a user has made their own. */
    public const DUB_DEFAULTS = ['gender' => 'female', 'dub' => true, 'review' => false, 'original_volume' => 0.0, 'separate' => false];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'dub_options' => 'array',
        ];
    }

    /**
     * The choices the upload form starts with: the ones this user last sent a video with.
     *
     * @return array{gender: string, dub: bool, review: bool, original_volume: float}
     */
    public function dubOptions(): array
    {
        return [...self::DUB_DEFAULTS, ...($this->dub_options ?? [])];
    }

    /**
     * The videos this user uploaded.
     *
     * @return HasMany<VideoJob, $this>
     */
    public function videoJobs(): HasMany
    {
        return $this->hasMany(VideoJob::class);
    }
}
