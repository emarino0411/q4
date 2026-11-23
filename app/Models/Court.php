<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Court extends Model
{
    protected $fillable = ['game_id', 'court_number', 'type', 'status', 'idled_at', 'active', 'code'];

    protected $casts = [
        'idled_at' => 'datetime',
        'active'   => 'boolean',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    public function currentMatch(): HasOne
    {
        return $this->hasOne(GameMatch::class)->where('status', 'ongoing')->latest();
    }

    public function isIdle(): bool     { return $this->status === 'idle'; }
    public function isAssigned(): bool  { return $this->status === 'assigned'; }
    public function isPlaying(): bool   { return $this->status === 'playing'; }
    public function isActive(): bool    { return (bool) $this->active; }
}
