<?php

use App\Models\Game;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app', ['header' => 'Dashboard'])] class extends Component
{
    public function with(): array
    {
        $user = Auth::user();

        $games       = $user->games()->withCount('players')->latest('date')->get();
        $activeGame  = $games->first(fn($g) => $g->isOngoing());
        $totalPlayers = $games->sum('players_count');
        $totalMatches = GameMatch::whereHas('court', fn($q) => $q->whereIn('game_id', $games->pluck('id')))
            ->where('status', 'finished')
            ->count();

        return [
            'activeGame'   => $activeGame,
            'totalGames'   => $games->count(),
            'totalPlayers' => $totalPlayers,
            'totalMatches' => $totalMatches,
            'recentGames'  => $games->take(5),
        ];
    }
}; ?>

<div class="space-y-6">

        {{-- Active game banner --}}
        @if ($activeGame)
            <div class="flex items-center justify-between bg-wimbledon-600 dark:bg-wimbledon-700 text-white rounded-xl px-6 py-4">
                <div>
                    <p class="text-xs font-medium opacity-75 uppercase tracking-wider mb-0.5">Active Game</p>
                    <p class="text-lg font-semibold">{{ $activeGame->name }}</p>
                    <p class="text-sm opacity-75">{{ $activeGame->date->format('M d, Y') }} · {{ $activeGame->players_count }} players</p>
                </div>
                <a href="{{ route('games.queue', $activeGame->id) }}" wire:navigate
                   class="shrink-0 bg-white/20 hover:bg-white/30 transition text-white text-sm font-medium px-4 py-2 rounded-lg">
                    Open Queue →
                </a>
            </div>
        @endif

        {{-- Stats --}}
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-6 py-5">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Total Games</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $totalGames }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-6 py-5">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Total Players</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $totalPlayers }}</p>
            </div>
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-6 py-5">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Matches Played</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $totalMatches }}</p>
            </div>
        </div>

        {{-- Recent games --}}
        @if ($recentGames->isNotEmpty())
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Recent Games</h3>
                    <a href="{{ route('games') }}" wire:navigate class="text-xs text-wimbledon-600 dark:text-wimbledon-400 hover:underline">View all</a>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($recentGames as $game)
                        <li class="flex items-center justify-between px-6 py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $game->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $game->date->format('M d, Y') }} · {{ $game->players_count }} players</p>
                            </div>
                            <div class="flex items-center gap-3">
                                @if ($game->isPending())
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Pending</span>
                                @elseif ($game->isOngoing())
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-400">Ongoing</span>
                                    <a href="{{ route('games.queue', $game->id) }}" wire:navigate class="text-xs text-wimbledon-600 dark:text-wimbledon-400 hover:underline">Queue</a>
                                @else
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400">Ended</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center text-sm text-gray-500 dark:text-gray-400">
                No games yet. <a href="{{ route('games') }}" wire:navigate class="text-wimbledon-600 dark:text-wimbledon-400 hover:underline">Create your first game →</a>
            </div>
        @endif

</div>
