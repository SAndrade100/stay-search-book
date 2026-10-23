<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    public const MAX_API_TOKENS = 5;

    public function issueApiToken(): string
    {
        $plainText = $this->createToken('api')->plainTextToken;

        $staleIds = $this->tokens()->latest('id')->offset(self::MAX_API_TOKENS)->limit(PHP_INT_MAX)->pluck('id');
        $this->tokens()->whereKey($staleIds)->delete();

        return $plainText;
    }

    public function books(): HasMany 
    {
        return $this->hasmany(Book::class);
    }

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
        ];
    }
}
