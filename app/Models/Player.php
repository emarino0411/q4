<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = [
        'game_id', 'name', 'level', 'gender',
        'play_with_novice', 'novice_play_count', 'play_with_low_intermediate', 'low_intermediate_play_count',
        'status', 'paid', 'wins', 'losses', 'queued_at', 'queued_mode',
    ];

    protected $casts = [
        'play_with_novice'           => 'boolean',
        'play_with_low_intermediate' => 'boolean',
        'paid'                       => 'boolean',
        'queued_at'                  => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class);
    }

    public function lastResult(): ?string
    {
        return $this->matchPlayers()
            ->whereNotNull('result')
            ->latest()
            ->value('result');
    }

    public function getLevelLabelAttribute(): string
    {
        return match ($this->level) {
            'novice'           => 'Novice',
            'low_intermediate' => 'Low Intermediate',
            'hi_intermediate'  => 'Hi Intermediate',
            default            => $this->level,
        };
    }

    public function isQueued(): bool    { return $this->status === 'queued'; }
    public function isPlaying(): bool   { return $this->status === 'playing'; }
    public function isDone(): bool      { return $this->status === 'done'; }
    public function isInactive(): bool  { return $this->status === 'inactive'; }
}
