<?php

use App\Models\Court;
use App\Models\RequestGroup;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.display')] class extends Component
{
    public Court $court;

    public function mount(string $code): void
    {
        $this->court = Court::where('code', strtoupper($code))->firstOrFail();
    }

    public function with(): array
    {
        $this->court->refresh();
        $court = $this->court->load(['game', 'currentMatch.matchPlayers.player']);
        $game  = $court->game;

        // Queue position: how many ready sets are ahead of this court becoming free
        $requestGroups = RequestGroup::with('players')
            ->where('game_id', $game->id)
            ->where('status', 'ready')
            ->get();

        $queuedPlayers = $game->players()
            ->where('status', 'queued')
            ->oldest()
            ->get()
            ->filter(fn($p) => !$requestGroups->flatMap(fn($rg) => $rg->players->pluck('id'))->contains($p->id))
            ->values();

        // Build up-next sets (groups of 4)
        $upNextSets = collect();
        $pool = $queuedPlayers;
        while ($pool->count() >= 4) {
            $upNextSets->push($pool->take(4));
            $pool = $pool->slice(4)->values();
        }
        // Merge ready RGs
        foreach ($requestGroups as $rg) {
            $upNextSets->push($rg->players->filter(fn($p) => $p->status === 'queued')->values());
        }

        return compact('court', 'game', 'upNextSets');
    }
}; ?>

<div wire:poll.5000ms class="min-h-screen bg-gray-50 dark:bg-gray-950 flex flex-col items-center justify-start p-6">

    {{-- Header --}}
    <div class="w-full max-w-md mb-6 text-center">
        <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">{{ $game->name }}</p>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Court {{ $court->court_number }}</h1>
        <p class="text-xs font-mono text-gray-400 dark:text-gray-500 mt-1 tracking-widest">{{ $court->code }}</p>
    </div>

    {{-- Court Status --}}
    <div class="w-full max-w-md mb-6">
        @if ($court->isPlaying())
            @php $match = $court->currentMatch; $team1 = $match->matchPlayers->where('team', 1); $team2 = $match->matchPlayers->where('team', 2); @endphp
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-green-400 dark:border-green-600 overflow-hidden shadow-sm">
                <div class="px-5 py-3 bg-green-50 dark:bg-green-900/20 border-b border-green-100 dark:border-green-800 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                        <span class="text-sm font-semibold text-green-700 dark:text-green-400">Playing</span>
                    </div>
                    @if ($match->started_at)
                        <span x-data="timer({{ $match->started_at->timestamp }})" x-text="elapsed" class="font-mono text-sm text-green-600 dark:text-green-400"></span>
                    @endif
                </div>
                <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 px-5 py-4">
                    <div class="space-y-2">
                        @foreach ($team1 as $mp)
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $mp->player->name }}</p>
                        @endforeach
                    </div>
                    <span class="text-gray-300 dark:text-gray-600 font-bold text-lg">vs</span>
                    <div class="space-y-2 text-right">
                        @foreach ($team2 as $mp)
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $mp->player->name }}</p>
                        @endforeach
                    </div>
                </div>
            </div>

        @elseif ($court->isAssigned())
            @php $match = $court->currentMatch; $team1 = $match->matchPlayers->where('team', 1); $team2 = $match->matchPlayers->where('team', 2); @endphp
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-yellow-400 dark:border-yellow-600 overflow-hidden shadow-sm">
                <div class="px-5 py-3 bg-yellow-50 dark:bg-yellow-900/20 border-b border-yellow-100 dark:border-yellow-800">
                    <span class="text-sm font-semibold text-yellow-700 dark:text-yellow-400">⏳ Ready to Start</span>
                </div>
                <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 px-5 py-4">
                    <div class="space-y-2">
                        @foreach ($team1 as $mp)
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $mp->player->name }}</p>
                        @endforeach
                    </div>
                    <span class="text-gray-300 dark:text-gray-600 font-bold text-lg">vs</span>
                    <div class="space-y-2 text-right">
                        @foreach ($team2 as $mp)
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $mp->player->name }}</p>
                        @endforeach
                    </div>
                </div>
            </div>

        @else
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 px-5 py-8 text-center shadow-sm {{ !$court->active ? 'opacity-50' : '' }}">
                @if (!$court->active)
                    <p class="text-sm font-semibold text-gray-400 dark:text-gray-500">Court is Off</p>
                @else
                    <p class="text-2xl mb-1">🎾</p>
                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Court is Idle</p>
                    @if ($court->idled_at)
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 font-mono"
                            x-data="timer({{ $court->idled_at->timestamp }})" x-text="elapsed"></p>
                    @endif
                @endif
            </div>
        @endif
    </div>

    {{-- Up Next --}}
    @if ($upNextSets->isNotEmpty())
        <div class="w-full max-w-md">
            <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-3">Up Next</p>
            <div class="space-y-2">
                @foreach ($upNextSets as $i => $set)
                    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3 flex items-center gap-3 shadow-sm">
                        <span class="text-xs font-bold text-gray-400 dark:text-gray-500 w-5 flex-shrink-0">#{{ $i + 1 }}</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($set as $player)
                                <span class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-medium">{{ $player->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <p class="mt-8 text-xs text-gray-300 dark:text-gray-600">Auto-refreshes every 5 seconds</p>
</div>

@script
<script>
    Alpine.data('timer', (start) => ({
        elapsed: '',
        _interval: null,
        init() { this.tick(); this._interval = setInterval(() => this.tick(), 1000); },
        destroy() { clearInterval(this._interval); },
        tick() {
            const s = Math.max(0, Math.floor(Date.now() / 1000) - start);
            this.elapsed = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
        },
    }));
</script>
@endscript
