<?php

use App\Models\Game;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Game $game;
    public ?int $selectedPlayerId = null;
    public ?int $swappingMatchId   = null;
    public ?int $editingMatchId    = null;
    public ?int $editingScoreMatchId = null;
    public int  $editScore1        = 0;
    public int  $editScore2        = 0;

    public function mount(Game $game): void
    {
        abort_unless(Auth::user()->games()->where('id', $game->id)->exists(), 403);
    }

    public function selectPlayer(int $playerId): void
    {
        $this->selectedPlayerId = $playerId;
    }

    public function closePlayer(): void
    {
        $this->selectedPlayerId = null;
    }

    public function openEditScore(int $matchId): void
    {
        if ($this->editingScoreMatchId === $matchId) {
            $this->editingScoreMatchId = null;
            return;
        }
        $match = GameMatch::whereHas('court', fn($q) => $q->where('game_id', $this->game->id))
            ->where('status', 'finished')->findOrFail($matchId);
        $this->editingScoreMatchId = $matchId;
        $this->editScore1          = (int) $match->team1_score;
        $this->editScore2          = (int) $match->team2_score;
        $this->editingMatchId      = null;
        $this->swappingMatchId     = null;
    }

    public function updateScore(): void
    {
        $this->validate(['editScore1' => 'required|integer|min:0', 'editScore2' => 'required|integer|min:0']);

        $match = GameMatch::with('matchPlayers.player')
            ->whereHas('court', fn($q) => $q->where('game_id', $this->game->id))
            ->where('status', 'finished')
            ->findOrFail($this->editingScoreMatchId);

        $winningTeam = $this->editScore1 > $this->editScore2 ? 1 : ($this->editScore2 > $this->editScore1 ? 2 : null);

        DB::transaction(function () use ($match, $winningTeam) {
            $match->update(['team1_score' => $this->editScore1, 'team2_score' => $this->editScore2]);

            if ($winningTeam) {
                foreach ($match->matchPlayers as $mp) {
                    $mp->update(['result' => $mp->team === $winningTeam ? 'win' : 'loss']);
                }
                foreach ($match->matchPlayers as $mp) {
                    $mp->player->update([
                        'wins'   => $mp->player->matchPlayers()->where('result', 'win')->count(),
                        'losses' => $mp->player->matchPlayers()->where('result', 'loss')->count(),
                    ]);
                }
            }
        });

        $this->editingScoreMatchId = null;
    }

    public function openEdit(int $matchId): void
    {
        $this->editingMatchId      = $this->editingMatchId === $matchId ? null : $matchId;
        $this->swappingMatchId     = null;
        $this->editingScoreMatchId = null;
    }

    public function setWinner(int $matchId, int $winningTeam): void
    {
        $match = GameMatch::with('matchPlayers.player')
            ->whereHas('court', fn($q) => $q->where('game_id', $this->game->id))
            ->where('status', 'finished')
            ->findOrFail($matchId);

        abort_unless(in_array($winningTeam, [1, 2]), 422);
        $losingTeam = $winningTeam === 1 ? 2 : 1;

        DB::transaction(function () use ($match, $winningTeam, $losingTeam) {
            foreach ($match->matchPlayers as $mp) {
                $mp->update(['result' => $mp->team === $winningTeam ? 'win' : 'loss']);
            }
            // Recalculate all affected players
            foreach ($match->matchPlayers as $mp) {
                $mp->player->update([
                    'wins'   => $mp->player->matchPlayers()->where('result', 'win')->count(),
                    'losses' => $mp->player->matchPlayers()->where('result', 'loss')->count(),
                ]);
            }
        });

        $this->editingMatchId = null;
    }

    public function openSwap(int $matchId): void
    {
        $this->swappingMatchId     = $this->swappingMatchId === $matchId ? null : $matchId;
        $this->editingMatchId      = null;
        $this->editingScoreMatchId = null;
    }

    public function confirmSwap(int $mpIdA, int $mpIdB): void
    {
        $mpA = \App\Models\MatchPlayer::with('player')->findOrFail($mpIdA);
        $mpB = \App\Models\MatchPlayer::with('player')->findOrFail($mpIdB);

        // Both must belong to the same match within this game
        abort_unless($mpA->match_id === $mpB->match_id, 403);
        abort_unless(
            GameMatch::whereHas('court', fn($q) => $q->where('game_id', $this->game->id))
                ->where('id', $mpA->match_id)->exists(),
            403
        );

        DB::transaction(function () use ($mpA, $mpB) {
            $playerIdA = $mpA->player_id;
            $mpA->update(['player_id' => $mpB->player_id]);
            $mpB->update(['player_id' => $playerIdA]);

            // Recalculate wins/losses for all 4 affected players
            foreach ([$mpA->player_id, $mpB->player_id, $playerIdA, $mpB->player->id] as $pid) {
                $player = \App\Models\Player::find($pid);
                if (!$player) continue;
                $player->update([
                    'wins'   => $player->matchPlayers()->where('result', 'win')->count(),
                    'losses' => $player->matchPlayers()->where('result', 'loss')->count(),
                ]);
            }
        });

        $this->swappingMatchId = null;
    }

    public function voidMatch(int $matchId): void
    {
        $match = GameMatch::with('matchPlayers.player')
            ->whereHas('court', fn($q) => $q->where('game_id', $this->game->id))
            ->where('status', 'finished')
            ->findOrFail($matchId);

        DB::transaction(function () use ($match) {
            foreach ($match->matchPlayers as $mp) {
                if ($mp->result === 'win') {
                    $mp->player->decrement('wins');
                } elseif ($mp->result === 'loss') {
                    $mp->player->decrement('losses');
                }
                $mp->update(['result' => null]);
            }
            $match->update([
                'status'      => 'voided',
                'team1_score' => null,
                'team2_score' => null,
            ]);
        });
    }

    public function with(): array
    {
        $totalMatches = GameMatch::whereHas('court', fn($q) => $q->where('game_id', $this->game->id))
            ->where('status', 'finished')
            ->count();

        $allStats = $this->game->players()
            ->where(fn($q) => $q->where('wins', '>', 0)->orWhere('losses', '>', 0))
            ->get()
            ->map(function ($player) {
                $games  = $player->wins + $player->losses;
                $winPct = $games > 0 ? $player->wins / $games : 0;
                $points = (int) ($player->matchPlayers()
                    ->join('matches', 'match_players.match_id', '=', 'matches.id')
                    ->where('matches.status', 'finished')
                    ->selectRaw('SUM(CASE WHEN match_players.team = 1 THEN matches.team1_score ELSE matches.team2_score END) as total')
                    ->value('total') ?? 0);
                return ['player' => $player, 'games' => $games, 'win_pct' => $winPct, 'points' => $points];
            })
            ->pipe(fn($col) => $col->sort(fn($a, $b) =>
                [$b['player']->wins, $b['win_pct'], $b['points']] <=> [$a['player']->wins, $a['win_pct'], $a['points']]
            )->values());

        $selectedPlayer = null;
        $playerHistory  = collect();
        $playerPoints   = 0;

        if ($this->selectedPlayerId) {
            $selectedPlayer = $this->game->players()->find($this->selectedPlayerId);
            if ($selectedPlayer) {
                $playerHistory = $selectedPlayer->matchPlayers()
                    ->with(['gameMatch.matchPlayers.player', 'gameMatch.court'])
                    ->whereHas('gameMatch', fn($q) => $q->whereIn('status', ['finished', 'voided']))
                    ->latest()
                    ->get();
                $playerPoints = (int) ($selectedPlayer->matchPlayers()
                    ->join('matches', 'match_players.match_id', '=', 'matches.id')
                    ->where('matches.status', 'finished')
                    ->selectRaw('SUM(CASE WHEN match_players.team = 1 THEN matches.team1_score ELSE matches.team2_score END) as total')
                    ->value('total') ?? 0);
            }
        }

        return [
            'totalMatches'   => $totalMatches,
            'statTabs'       => [
                'overall'          => $allStats,
                'hi_intermediate'  => $allStats->filter(fn($r) => $r['player']->level === 'hi_intermediate')->values(),
                'low_intermediate' => $allStats->filter(fn($r) => $r['player']->level === 'low_intermediate')->values(),
                'novice'           => $allStats->filter(fn($r) => $r['player']->level === 'novice')->values(),
            ],
            'selectedPlayer' => $selectedPlayer,
            'playerHistory'  => $playerHistory,
            'playerPoints'   => $playerPoints,
        ];
    }
}; ?>

<div x-data="{ tab: 'overall' }">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('games') }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $game->name }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $game->date->format('M d, Y') }}</p>
            </div>
        </div>
        @if ($game->isOngoing())
            <a href="{{ route('games.queue', $game->id) }}" wire:navigate
               class="text-sm px-4 py-2 rounded-lg bg-wimbledon-600 hover:bg-wimbledon-700 text-white font-medium transition">
                Open Queue →
            </a>
        @endif
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Players</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $game->players()->count() }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Courts</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $game->number_of_courts }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Matches</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $totalMatches }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-5 py-4 text-center">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Status</p>
            @if ($game->isPending())
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Pending</span>
            @elseif ($game->isOngoing())
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-400">Ongoing</span>
            @else
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400">Ended</span>
            @endif
        </div>
    </div>

    {{-- Stats + slide-over layout --}}
    <div class="flex gap-6 items-start transition-all duration-300">

        {{-- Stats table --}}
        <div class="flex-1 min-w-0 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            {{-- Tabs --}}
            <div class="flex gap-1 px-4 border-b border-gray-200 dark:border-gray-700">
                @foreach (['overall' => 'Overall', 'hi_intermediate' => 'Hi Int', 'low_intermediate' => 'Low Int', 'novice' => 'Novice'] as $key => $label)
                    <button x-on:click="tab = '{{ $key }}'"
                        class="px-3 py-3 text-xs font-medium border-b-2 -mb-px transition"
                        :class="tab === '{{ $key }}' ? 'border-wimbledon-600 text-wimbledon-600 dark:text-wimbledon-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @foreach ($statTabs as $tabKey => $stats)
                <div x-show="tab === '{{ $tabKey }}'" x-cloak>
                    @if ($stats->isEmpty())
                        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-12">No completed matches yet.</p>
                    @else
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <th class="px-4 py-2.5 text-left w-8">#</th>
                                    <th class="px-4 py-2.5 text-left">Player</th>
                                    <th class="px-4 py-2.5 text-center">W</th>
                                    <th class="px-4 py-2.5 text-center">L</th>
                                    <th class="px-4 py-2.5 text-center">Games</th>
                                    <th class="px-4 py-2.5 text-center">Win %</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($stats as $i => $row)
                                    @php
                                        $p        = $row['player'];
                                        $winPct   = round($row['win_pct'] * 100);
                                        $lvlColor = match($p->level) {
                                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                        };
                                        $isSelected = $selectedPlayerId === $p->id;
                                    @endphp
                                    <tr wire:click="selectPlayer({{ $p->id }})"
                                        class="cursor-pointer transition {{ $isSelected ? 'bg-wimbledon-50 dark:bg-wimbledon-900/20' : 'bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                                        <td class="px-4 py-3 text-xs font-bold {{ $i === 0 ? 'text-yellow-500' : ($i === 1 ? 'text-gray-400' : ($i === 2 ? 'text-amber-600' : 'text-gray-400 dark:text-gray-500')) }}">
                                            {{ $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i + 1)) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-gray-900 dark:text-white">{{ $p->name }}</span>
                                                @if ($tabKey === 'overall')
                                                    <span class="text-xs px-1.5 py-0.5 rounded-full {{ $lvlColor }}">{{ $p->level_label }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-center font-semibold text-green-600 dark:text-green-400">{{ $p->wins }}</td>
                                        <td class="px-4 py-3 text-center font-semibold text-red-500 dark:text-red-400">{{ $p->losses }}</td>
                                        <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">{{ $row['games'] }}</td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <div class="flex-1 bg-gray-100 dark:bg-gray-700 rounded-full h-1.5">
                                                    <div class="h-1.5 rounded-full {{ $winPct >= 60 ? 'bg-green-500' : ($winPct >= 40 ? 'bg-yellow-400' : 'bg-red-400') }}" style="width: {{ $winPct }}%"></div>
                                                </div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400 w-8 text-right">{{ $winPct }}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Player history panel --}}
        @if ($selectedPlayer)
            @php
                $spGames  = $selectedPlayer->wins + $selectedPlayer->losses;
                $spWinPct = $spGames > 0 ? round($selectedPlayer->wins / $spGames * 100) : 0;
                $spLvlColor = match($selectedPlayer->level) {
                    'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                    'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                    'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                };
            @endphp
            <div class="w-[28rem] flex-shrink-0 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                {{-- Panel header --}}
                <div class="flex items-start justify-between px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $selectedPlayer->name }}</p>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="text-xs px-1.5 py-0.5 rounded-full {{ $spLvlColor }}">{{ $selectedPlayer->level_label }}</span>
                            <span class="text-xs text-gray-400 capitalize">{{ $selectedPlayer->gender }}</span>
                        </div>
                    </div>
                    <button wire:click="closePlayer" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition mt-0.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Mini stats --}}
                <div class="grid grid-cols-4 divide-x divide-gray-100 dark:divide-gray-700 border-b border-gray-100 dark:border-gray-700">
                    <div class="px-3 py-2.5 text-center">
                        <p class="text-base font-bold text-green-600 dark:text-green-400">{{ $selectedPlayer->wins }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">W</p>
                    </div>
                    <div class="px-3 py-2.5 text-center">
                        <p class="text-base font-bold text-red-500 dark:text-red-400">{{ $selectedPlayer->losses }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">L</p>
                    </div>
                    <div class="px-3 py-2.5 text-center">
                        <p class="text-base font-bold text-wimbledon-600 dark:text-wimbledon-400">{{ $playerPoints }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">Pts</p>
                    </div>
                    <div class="px-3 py-2.5 text-center">
                        <p class="text-base font-bold text-gray-700 dark:text-gray-300">{{ $spWinPct }}%</p>
                        <p class="text-xs text-gray-400 mt-0.5">Win%</p>
                    </div>
                </div>

                {{-- Match list --}}
                <div class="overflow-y-auto max-h-[60vh]">
                    @if ($playerHistory->isEmpty())
                        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-10">No matches yet.</p>
                    @else
                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($playerHistory as $vmp)
                                @php
                                    $vm     = $vmp->gameMatch;
                                    $vt1    = $vm->matchPlayers->where('team', 1);
                                    $vt2    = $vm->matchPlayers->where('team', 2);
                                    $vt1win = ($vm->team1_score ?? 0) > ($vm->team2_score ?? 0);
                                    $vt2win = ($vm->team2_score ?? 0) > ($vm->team1_score ?? 0);
                                    $won    = $vmp->result === 'win';
                                    $voided = $vm->status === 'voided';
                                @endphp
                                <div class="px-4 py-3 {{ $voided ? 'opacity-50' : '' }}">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <div class="flex items-center gap-1.5">
                                            @if ($voided)
                                                <span class="text-xs font-semibold text-gray-400 line-through">Voided</span>
                                            @else
                                                <span class="text-xs font-semibold {{ $won ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $won ? 'Win' : 'Loss' }}</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 text-xs text-gray-400">
                                            @if ($vm->court) <span>Ct {{ $vm->court->court_number }}</span> @endif
                                            <span>{{ $vm->finished_at?->format('g:i a') }}</span>
                                            @if (!$voided)
                                                <button wire:click="openEdit({{ $vm->id }})" class="text-xs px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 hover:bg-amber-200 transition font-medium">✎ Result</button>
                                                <button wire:click="openEditScore({{ $vm->id }})" class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 hover:bg-blue-200 transition font-medium">✎ Score</button>
                                                <button wire:click="openSwap({{ $vm->id }})" class="text-xs px-1.5 py-0.5 rounded bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/30 dark:text-wimbledon-400 hover:bg-wimbledon-200 transition font-medium">⇄ Swap</button>
                                                <button wire:click="voidMatch({{ $vm->id }})" wire:confirm="Void this match? Win/loss counts will be reversed for all players." class="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-500 dark:bg-red-900/30 dark:text-red-400 hover:bg-red-200 transition font-medium">Void</button>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Score row --}}
                                    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 {{ $swappingMatchId === $vm->id ? 'mb-3' : '' }}">
                                        <div class="space-y-0.5">
                                            @foreach ($vt1 as $vmp2)
                                                <p class="text-xs {{ $vmp2->player_id === $selectedPlayer->id ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">{{ $vmp2->player->name }}</p>
                                            @endforeach
                                        </div>
                                        <div class="text-center px-1">
                                            @if ($voided)
                                                <span class="text-sm text-gray-400">— —</span>
                                            @else
                                                <span class="text-sm font-bold {{ $vt1win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $vm->team1_score }}</span>
                                                <span class="text-gray-300 dark:text-gray-600 mx-0.5">–</span>
                                                <span class="text-sm font-bold {{ $vt2win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $vm->team2_score }}</span>
                                            @endif
                                        </div>
                                        <div class="space-y-0.5 text-right">
                                            @foreach ($vt2 as $vmp2)
                                                <p class="text-xs {{ $vmp2->player_id === $selectedPlayer->id ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">{{ $vmp2->player->name }}</p>
                                            @endforeach
                                        </div>
                                    </div>

                                    {{-- Edit Score UI --}}
                                    @if (!$voided && $editingScoreMatchId === $vm->id)
                                        <div class="mt-2 mb-1">
                                            <p class="text-xs text-gray-400 mb-1.5">Update score:</p>
                                            <div class="flex items-center gap-2">
                                                <div class="flex-1">
                                                    <p class="text-xs text-gray-400 mb-1">Team 1</p>
                                                    <input wire:model="editScore1" type="number" min="0"
                                                        class="w-full text-center text-sm font-bold rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-wimbledon-500" />
                                                </div>
                                                <span class="text-gray-300 dark:text-gray-600 mt-4">–</span>
                                                <div class="flex-1">
                                                    <p class="text-xs text-gray-400 mb-1">Team 2</p>
                                                    <input wire:model="editScore2" type="number" min="0"
                                                        class="w-full text-center text-sm font-bold rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-wimbledon-500" />
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 mt-2">
                                                <button wire:click="updateScore" class="flex-1 text-xs py-1.5 rounded-lg bg-wimbledon-600 hover:bg-wimbledon-700 text-white font-medium transition">Save</button>
                                                <button wire:click="openEditScore({{ $vm->id }})" class="text-xs text-gray-400 hover:text-gray-600 transition">Cancel</button>
                                            </div>
                                        </div>
                                    @endif

                                    {{-- Edit Result UI --}}
                                    @if (!$voided && $editingMatchId === $vm->id)
                                        @php
                                            $t1names = $vt1->map(fn($m) => $m->player->name)->implode(' & ');
                                            $t2names = $vt2->map(fn($m) => $m->player->name)->implode(' & ');
                                        @endphp
                                        <div class="mt-2 mb-1 space-y-1">
                                            <p class="text-xs text-gray-400 mb-1.5">Who won?</p>
                                            <button wire:click="setWinner({{ $vm->id }}, 1)"
                                                class="w-full text-left text-xs px-3 py-2 rounded-lg border transition {{ $vt1win ? 'border-green-400 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-green-400 hover:bg-green-50 dark:hover:bg-green-900/20' }}">
                                                🏆 Team 1 — {{ $t1names }}
                                            </button>
                                            <button wire:click="setWinner({{ $vm->id }}, 2)"
                                                class="w-full text-left text-xs px-3 py-2 rounded-lg border transition {{ $vt2win ? 'border-green-400 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 font-semibold' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:border-green-400 hover:bg-green-50 dark:hover:bg-green-900/20' }}">
                                                🏆 Team 2 — {{ $t2names }}
                                            </button>
                                            <button wire:click="openEdit({{ $vm->id }})" class="text-xs text-gray-400 hover:text-gray-600 transition">Cancel</button>
                                        </div>
                                    @endif

                                    {{-- Swap UI --}}
                                    @if (!$voided && $swappingMatchId === $vm->id)
                                        @php $allMps = $vt1->merge($vt2); @endphp
                                        <p class="text-xs text-gray-400 mb-1.5">Pick two players to swap:</p>
                                        <div class="grid grid-cols-2 gap-1">
                                            @foreach ($allMps as $mpA)
                                                @foreach ($allMps as $mpB)
                                                    @if ($mpA->id < $mpB->id)
                                                        <button wire:click="confirmSwap({{ $mpA->id }}, {{ $mpB->id }})" wire:confirm="Swap {{ $mpA->player->name }} ↔ {{ $mpB->player->name }}? Their win/loss records for this match will be exchanged."
                                                            class="text-xs px-2 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-wimbledon-100 dark:hover:bg-wimbledon-900/30 hover:text-wimbledon-700 dark:hover:text-wimbledon-400 transition text-left">
                                                            {{ $mpA->player->name }} ↔ {{ $mpB->player->name }}
                                                        </button>
                                                    @endif
                                                @endforeach
                                            @endforeach
                                        </div>
                                        <button wire:click="openSwap({{ $vm->id }})" class="mt-2 text-xs text-gray-400 hover:text-gray-600 transition">Cancel</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

    </div>

</div>
