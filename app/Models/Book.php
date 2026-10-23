<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'isbn', 'value'])]
class Book extends Model
{
    /** @use HasFactory<\Database\Factories\BookFactory> */
    use HasFactory;

    protected function casts(): array 
    {
        return [
            'value' => 'decimal:2',
        ];
    }

    public function scopeNameContains(Builder $query, string $term): void 
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $query->whereRaw($this->qualifyColumn('name')." like ? escape '!'", ["%{$escaped}"]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
