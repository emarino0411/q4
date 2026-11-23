<?php

use App\Models\Game;
use App\Models\GameMatch;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.display')] class extends Component
{
    public Game $game;

    public function mount(Game $game): void
    {
        abort_unless($game->isOngoing() || $game->isEnded(), 404);
        $this->game = $game;
    }

    public function with(): array
    {
        $this->game->refresh();
        $this->game->load('winner');

        if ($this->game->isEnded()) {
            $topPlayers = $this->game->players()
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
                    return [
                        'player'  => $player,
                        'games'   => $games,
                        'win_pct' => $winPct,
                        'points'  => $points,
                    ];
                })
                ->pipe(function ($col) {
                    return $col->sort(function ($a, $b) {
                        if ($b['player']->wins !== $a['player']->wins) return $b['player']->wins <=> $a['player']->wins;
                        if ($b['win_pct'] !== $a['win_pct'])           return $b['win_pct'] <=> $a['win_pct'];
                        return $b['points'] <=> $a['points'];
                    })->values();
                });

            return [
                'courts'     => collect(),
                'upNext'     => collect(),
                'topPlayers' => [
                    'overall'          => $topPlayers->values(),
                    'hi_intermediate'  => $topPlayers->filter(fn($r) => $r['player']->level === 'hi_intermediate')->values(),
                    'low_intermediate' => $topPlayers->filter(fn($r) => $r['player']->level === 'low_intermediate')->values(),
                    'novice'           => $topPlayers->filter(fn($r) => $r['player']->level === 'novice')->values(),
                ],
            ];
        }

        $courts = $this->game->courts()
            ->with(['currentMatch.matchPlayers.player'])
            ->get();

        $queue = $this->game->players()
            ->where('status', 'queued')
            ->oldest()
            ->get();

        $activeMode  = $this->game->pairing_mode;
        $pendingMode = $this->game->pending_pairing_mode;

        $groupedQueue = $this->buildGroupedQueue($queue, $activeMode);
        if ($pendingMode) {
            $legacyQueue  = $queue->where('queued_mode', $activeMode)->values();
            $newQueue     = $queue->where('queued_mode', '!=', $activeMode)->values();
            $groupedQueue = array_merge(
                $this->buildGroupedQueue($legacyQueue, $activeMode, '(Legacy) ') ?? [],
                $this->buildGroupedQueue($newQueue, $pendingMode) ?? []
            );
        }

        $upNext = collect();
        if ($groupedQueue) {
            foreach ($groupedQueue as $group) {
                $players = $group['players']->sortBy('queued_at')->values();
                while ($players->count() >= 4) {
                    $set     = $players->take(4);
                    $players = $players->slice(4)->values();
                    $upNext->push([
                        'label'     => $group['label'],
                        'color'     => $group['color'],
                        'players'   => $set,
                        'queued_at' => $set->min('queued_at'),
                        'ready'     => true,
                    ]);
                }
                if ($players->isNotEmpty()) {
                    $upNext->push([
                        'label'     => $group['label'],
                        'color'     => $group['color'],
                        'players'   => $players,
                        'queued_at' => $players->min('queued_at'),
                        'ready'     => false,
                    ]);
                }
            }
            $upNext = $upNext->sortBy('queued_at')->values();
        }

        $topPlayers = [];

        return compact('courts', 'upNext', 'topPlayers');
    }

    private function buildGroupedQueue($queue, string $mode, string $labelPrefix = ''): ?array
    {
        if ($mode === 'win_loss') {
            return [
                ['label' => $labelPrefix . 'Winners', 'color' => 'text-green-600 dark:text-green-400', 'players' => $queue->filter(fn($p) => $p->lastResult() !== 'loss')->values()],
                ['label' => $labelPrefix . 'Losers',  'color' => 'text-red-500 dark:text-red-400',     'players' => $queue->filter(fn($p) => $p->lastResult() === 'loss')->values()],
            ];
        }

        if ($mode === 'level') {
            $novicesExist = $queue->where('level', 'novice')->isNotEmpty();
            $lowExists    = $queue->where('level', 'low_intermediate')->isNotEmpty();
            return [
                ['label' => $labelPrefix . 'Hi Intermediate',  'color' => 'text-orange-600 dark:text-orange-400', 'players' => $queue->where('level', 'hi_intermediate')
                    ->filter(fn($p) => !($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist))
                    ->filter(fn($p) => !($p->play_with_low_intermediate && $lowExists))
                    ->values()],
                ['label' => $labelPrefix . 'Low Intermediate', 'color' => 'text-yellow-600 dark:text-yellow-400', 'players' => $queue->where('level', 'low_intermediate')
                    ->filter(fn($p) => !($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist))
                    ->merge($queue->where('level', 'hi_intermediate')->where('play_with_low_intermediate', true)->filter(fn($p) => !($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist))->sortBy('queued_at'))
                    ->values()],
                ['label' => $labelPrefix . 'Novice',           'color' => 'text-blue-600 dark:text-blue-400',    'players' => $queue->where('level', 'novice')
                    ->merge($queue->where('level', '!=', 'novice')->where('play_with_novice', true)->where('novice_play_count', '>', 0)->sortBy('queued_at'))
                    ->values()],
            ];
        }

        return null;
    }
}; ?>

<div wire:poll.5000ms>

@if ($game->isEnded())
    {{-- Game Ended Screen --}}
    <div class="min-h-screen px-4 py-16">
        {{-- Page header --}}
        <div class="text-center mb-12">
            <div class="text-6xl mb-4">🍻</div>
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-2">That's a wrap!</h1>
            <p class="text-lg text-gray-500 dark:text-gray-400 mb-1">{{ $game->name }}</p>
            <p class="text-sm text-gray-400 dark:text-gray-500">{{ $game->date->format('F d, Y') }}</p>
            @if ($game->winner_player_id && $game->winner)
                <div class="mt-6 inline-flex items-center gap-3 px-6 py-3 rounded-2xl bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-300 dark:border-yellow-600">
                    <span class="text-3xl">🏆</span>
                    <div class="text-left">
                        <p class="text-xs font-semibold text-yellow-600 dark:text-yellow-400 uppercase tracking-wider">Winner</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $game->winner->name }}</p>
                    </div>
                </div>
            @endif
        </div>

        @if (collect($topPlayers)->flatten(1)->isNotEmpty())
            @php
                $tiers = [
                    'overall'          => ['label' => 'Overall',          'emoji' => '🏆', 'bg' => 'bg-yellow-50 dark:bg-yellow-900/10',  'border' => 'border-yellow-300 dark:border-yellow-700',  'heading' => 'text-yellow-700 dark:text-yellow-400'],
                    'hi_intermediate'  => ['label' => 'Hi Intermediate',  'emoji' => '🟠', 'bg' => 'bg-orange-50 dark:bg-orange-900/10',  'border' => 'border-orange-300 dark:border-orange-700',  'heading' => 'text-orange-700 dark:text-orange-400'],
                    'low_intermediate' => ['label' => 'Low Intermediate', 'emoji' => '🟡', 'bg' => 'bg-amber-50 dark:bg-amber-900/10',    'border' => 'border-amber-300 dark:border-amber-700',    'heading' => 'text-amber-700 dark:text-amber-400'],
                    'novice'           => ['label' => 'Novice',           'emoji' => '🔵', 'bg' => 'bg-blue-50 dark:bg-blue-900/10',      'border' => 'border-blue-300 dark:border-blue-700',      'heading' => 'text-blue-700 dark:text-blue-400'],
                ];
            @endphp
            <div class="max-w-2xl mx-auto space-y-10">
                @foreach ($tiers as $key => $tier)
                    @if (!empty($topPlayers[$key]) && $topPlayers[$key]->isNotEmpty())
                        {{-- Screenshot-ready section card --}}
                        <div class="rounded-3xl border-2 {{ $tier['border'] }} {{ $tier['bg'] }} overflow-hidden">
                            {{-- Section header --}}
                            <div class="px-8 py-5 border-b {{ $tier['border'] }} text-center">
                                <div class="text-4xl mb-2">🍻</div>
                                <p class="text-xs font-semibold uppercase tracking-widest {{ $tier['heading'] }} mb-1">{{ $game->name }} · {{ $game->date->format('F d, Y') }}</p>
                                <h2 class="text-2xl font-bold {{ $tier['heading'] }}">{{ $tier['emoji'] }} {{ $tier['label'] }} Rankings</h2>
                            </div>
                            {{-- Rows --}}
                            <div class="px-6 py-5 flex flex-col gap-3">
                                @php
                                    $ranked = collect();
                                    $rank   = 0;
                                    $prev   = null;
                                    foreach ($topPlayers[$key] as $row) {
                                        $games  = $row['games'];
                                        $winPct = $games > 0 ? round($row['player']->wins / $games * 100) : 0;
                                        $key2   = $row['player']->wins . '_' . $row['win_pct'] . '_' . $row['points'];
                                        if ($key2 !== $prev) {
                                            $rank++;
                                            $prev = $key2;
                                        }
                                        if ($rank > 3) break;
                                        $ranked->push(array_merge($row, ['rank' => $rank, 'win_pct_display' => $winPct]));
                                    }
                                @endphp
                                @foreach ($ranked as $row)
                                    @php
                                        $p      = $row['player'];
                                        $medal  = ['🥇', '🥈', '🥉'][$row['rank'] - 1] ?? $row['rank'];
                                        $winPct = $row['win_pct_display'];
                                        $games  = $row['games'];
                                    @endphp
                                    <div class="flex items-center gap-4 bg-white dark:bg-gray-900 rounded-2xl border {{ $tier['border'] }} px-6 py-4 {{ $row['rank'] === 1 ? 'shadow-md' : '' }}">
                                        <span class="text-4xl w-12 text-center">{{ $medal }}</span>
                                        <div class="flex-1 text-left">
                                            <p class="text-xl font-bold text-gray-900 dark:text-white">{{ $p->name }}</p>
                                            <p class="text-sm text-gray-400 dark:text-gray-500 mt-0.5">{{ $p->wins }}W · {{ $p->losses }}L · {{ $games }} games · {{ $row['points'] }}pts</p>
                                        </div>

                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            <p class="text-center text-gray-400 dark:text-gray-500">No match results recorded.</p>
        @endif
    </div>

@else
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $game->name }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                {{ $game->date->format('M d, Y') }} · {{ $game->number_of_courts }} {{ Str::plural('court', $game->number_of_courts) }}
            </p>
        </div>
        <span class="flex items-center gap-1.5 text-xs text-green-600 dark:text-green-400 font-medium">
            <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span> Live
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

        {{-- Courts --}}
        <div class="lg:col-span-2 space-y-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Courts</h3>

            @foreach ($courts as $court)
                @php $match = $court->currentMatch; @endphp
                <div class="bg-white dark:bg-gray-900 rounded-xl border
                    {{ $court->isPlaying() ? 'border-green-400 dark:border-green-600' : ($court->isAssigned() ? 'border-yellow-400 dark:border-yellow-600' : 'border-gray-200 dark:border-gray-700') }} p-4">

                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white">Court {{ $court->court_number }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400">
                                {{ ucfirst($court->type) }}
                            </span>
                        </div>
                        @if ($court->isPlaying())
                            <span class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Playing
                            </span>
                        @elseif ($court->isAssigned())
                            <span class="text-xs text-yellow-600 dark:text-yellow-400 font-medium">Ready to start</span>
                        @else
                            <span class="text-xs text-gray-400 dark:text-gray-500">Idle</span>
                        @endif
                    </div>

                    @if ($match)
                        @php
                            $team1 = $match->matchPlayers->where('team', 1);
                            $team2 = $match->matchPlayers->where('team', 2);
                        @endphp
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ([1 => $team1, 2 => $team2] as $teamNum => $players)
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Team {{ $teamNum }}</p>
                                    @foreach ($players as $mp)
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="w-3 h-3 rounded-full flex-shrink-0 {{ match($mp->player->level) {
                                                'novice'           => 'bg-blue-400',
                                                'low_intermediate' => 'bg-yellow-400',
                                                'hi_intermediate'  => 'bg-orange-400',
                                            } }}"></span>
                                            <span class="text-2xl font-semibold text-gray-900 dark:text-white">
                                                {{ $mp->player->name }}{{ $mp->player->novice_play_count > 0 ? ' ' . str_repeat('😊', $mp->player->novice_play_count) : '' }}{{ $mp->player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $mp->player->low_intermediate_play_count) : '' }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-4">No players assigned</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Up Next --}}
        <div class="lg:sticky lg:top-6">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">Up Next</h3>

            @if ($upNext->isEmpty())
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-8 text-center">
                    <p class="text-sm text-gray-400 dark:text-gray-500">No players in queue</p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($upNext as $i => $set)
                        <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $set['ready'] ? 'border-wimbledon-300 dark:border-wimbledon-700' : 'border-gray-200 dark:border-gray-700' }} overflow-hidden">
                            <div class="px-4 py-2 border-b {{ $set['ready'] ? 'border-wimbledon-100 dark:border-wimbledon-800' : 'border-gray-100 dark:border-gray-700' }} flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Queue #{{ $i + 1 }}</span>
                                @if ($set['ready'])
                                    <span class="text-xs font-medium text-green-600 dark:text-green-400">Ready</span>
                                @else
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $set['players']->count() }}/4 waiting</span>
                                @endif
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($set['players'] as $i => $player)
                                    <div class="flex items-center justify-between px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-gray-400 w-4">{{ $i + 1 }}</span>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="w-3 h-3 rounded-full flex-shrink-0 {{ match($player->level) {
                                                        'novice'           => 'bg-blue-400',
                                                        'low_intermediate' => 'bg-yellow-400',
                                                        'hi_intermediate'  => 'bg-orange-400',
                                                    } }}"></span>
                                                    <p class="text-2xl font-semibold text-gray-900 dark:text-white">
                                                        {{ $player->name }}{{ $player->novice_play_count > 0 ? ' ' . str_repeat('😊', $player->novice_play_count) : '' }}{{ $player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $player->low_intermediate_play_count) : '' }}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-xs text-right">
                                            <span class="text-green-600 dark:text-green-400">{{ $player->wins }}W</span>
                                            <span class="text-gray-400 mx-0.5">/</span>
                                            <span class="text-red-500 dark:text-red-400">{{ $player->losses }}L</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

@endif
</div>
