<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RequestGroup extends Model
{
    protected $fillable = ['game_id', 'status'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'request_group_players');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isAssigned(): bool
    {
        return $this->status === 'assigned';
    }
}
