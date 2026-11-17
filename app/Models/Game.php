<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    protected $fillable = [
        'user_id', 'name', 'date', 'number_of_courts',
        'status', 'token', 'started_at',
        'pairing_mode', 'pending_pairing_mode', 'score_type', 'score_limit', 'game_duration',
        'winners_stay', 'auto_assign', 'strict_level', 'queue_wait_threshold',
    ];

    protected $casts = [
        'date'         => 'date',
        'started_at'   => 'datetime',
        'winners_stay' => 'boolean',
        'auto_assign'  => 'boolean',
        'strict_level' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class)->orderBy('court_number');
    }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isOngoing(): bool { return $this->status === 'ongoing'; }
    public function isEnded(): bool   { return $this->status === 'ended'; }

    public function start(): void
    {
        $startedAt = now();
        $this->update(['status' => 'ongoing', 'token' => Str::uuid(), 'started_at' => $startedAt]);

        for ($i = 1; $i <= $this->number_of_courts; $i++) {
            $this->courts()->create(['court_number' => $i, 'type' => 'doubles', 'status' => 'idle', 'idled_at' => $startedAt]);
        }
    }

    public function end(): void
    {
        $this->update(['status' => 'ended']);
    }

    public function restart(): void
    {
        $this->courts()->delete();
        $this->players()->update(['status' => 'queued', 'wins' => 0, 'losses' => 0, 'queued_at' => now()]);
        $this->update(['started_at' => null]);
        $this->start();
    }
}
