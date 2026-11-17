<?php

use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Game $game;
    public Player $player;

    public function mount(Game $game, Player $player): void
    {
        abort_unless(Auth::user()->games()->where('id', $game->id)->exists(), 403);
        abort_unless($player->game_id === $game->id, 404);
        $this->game   = $game;
        $this->player = $player;
    }

    public function with(): array
    {
        $history = $this->player->matchPlayers()
            ->with(['gameMatch.matchPlayers.player', 'gameMatch.court'])
            ->whereHas('gameMatch', fn($q) => $q->where('status', 'finished'))
            ->latest()
            ->get();

        $points = $this->player->matchPlayers()
            ->join('matches', 'match_players.match_id', '=', 'matches.id')
            ->where('matches.status', 'finished')
            ->selectRaw('SUM(CASE WHEN match_players.team = 1 THEN matches.team1_score ELSE matches.team2_score END) as total')
            ->value('total') ?? 0;

        return ['history' => $history, 'points' => (int) $points];
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('games.view', $game->id) }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $player->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5">
                @php
                    $levelColor = match($player->level) {
                        'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                        'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                        'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                    };
                @endphp
                <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                <span class="text-xs text-gray-400 capitalize">{{ $player->gender }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $game->name }}</span>
            </div>
        </div>
    </div>

    {{-- Stats --}}
    @php $games = $player->wins + $player->losses; $winPct = $games > 0 ? round($player->wins / $games * 100) : 0; @endphp
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $player->wins }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Wins</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-2xl font-bold text-red-500 dark:text-red-400">{{ $player->losses }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Losses</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-2xl font-bold text-wimbledon-600 dark:text-wimbledon-400">{{ $points }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Points</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ $winPct }}%</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Win %</p>
        </div>
    </div>

    {{-- Match History --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Match History ({{ $history->count() }})</p>
        </div>

        @if ($history->isEmpty())
            <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-12">No completed matches yet.</p>
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($history as $vmp)
                    @php
                        $vm     = $vmp->gameMatch;
                        $vt1    = $vm->matchPlayers->where('team', 1);
                        $vt2    = $vm->matchPlayers->where('team', 2);
                        $vt1win = $vm->team1_score > $vm->team2_score;
                        $vt2win = $vm->team2_score > $vm->team1_score;
                        $won    = $vmp->result === 'win';
                    @endphp
                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold {{ $won ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $won ? 'Win' : 'Loss' }}</span>
                            <div class="flex items-center gap-2 text-xs text-gray-400">
                                @if ($vm->court)
                                    <span>Court {{ $vm->court->court_number }}</span>
                                @endif
                                @if ($vm->pairing_mode)
                                    <span>· {{ $vm->pairing_mode === 'level' ? 'By Level' : 'Win/Loss' }}</span>
                                @endif
                                <span>{{ $vm->finished_at?->format('g:i a') }}</span>
                                @if ($vm->started_at && $vm->finished_at)
                                    <span>· {{ $vm->started_at->diffInMinutes($vm->finished_at) }}min</span>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
                            <div class="space-y-0.5">
                                @foreach ($vt1 as $vmp2)
                                    <p class="text-sm {{ $vmp2->player_id === $player->id ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">{{ $vmp2->player->name }}</p>
                                @endforeach
                            </div>
                            <div class="text-center px-3">
                                <span class="text-lg font-bold {{ $vt1win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $vm->team1_score }}</span>
                                <span class="text-gray-300 dark:text-gray-600 mx-1">–</span>
                                <span class="text-lg font-bold {{ $vt2win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $vm->team2_score }}</span>
                            </div>
                            <div class="space-y-0.5 text-right">
                                @foreach ($vt2 as $vmp2)
                                    <p class="text-sm {{ $vmp2->player_id === $player->id ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">{{ $vmp2->player->name }}</p>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
