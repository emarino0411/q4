<?php

use App\Models\Court;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\RequestGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.display')] class extends Component
{
    public Game $game;

    public array $requestPlayerIds  = [];
    public array $levelShuffleSeeds = [];
    public array $cancelledSets     = [];

    public ?int $viewingPlayerId = null;

    public function mount(Game $game): void
    {
        abort_unless(Auth::user()->games()->where('id', $game->id)->exists(), 403);
        abort_unless($game->isOngoing(), 404);
        $this->game = $game;
    }

    public function with(): array
    {
        $this->game->refresh();

        $requestGroups = RequestGroup::with('players')
            ->where('game_id', $this->game->id)
            ->whereIn('status', ['pending', 'ready', 'assigned'])
            ->latest()
            ->get()
            ->map(function ($rg) {
                $allFree = $rg->players->every(fn($p) => $p->status === 'queued');
                if ($allFree && $rg->status === 'pending') {
                    $rg->update(['status' => 'ready']);
                    $rg->status = 'ready';
                }
                return $rg;
            });

        $requestGroupPlayerIds = $requestGroups->flatMap(fn($rg) => $rg->players->pluck('id'))->unique();

        $queue = $this->game->players()
            ->where('status', 'queued')
            ->oldest()
            ->get()
            ->filter(fn($p) => !$requestGroupPlayerIds->contains($p->id))
            ->values();

        $activeMode  = $this->game->pairing_mode;
        $pendingMode = $this->game->pending_pairing_mode;

        if ($pendingMode) {
            $legacyQueue  = $queue->where('queued_mode', $activeMode)->values();
            $newQueue     = $queue->where('queued_mode', '!=', $activeMode)->values();
            $groupedQueue = array_merge(
                $this->buildGroupedQueue($legacyQueue, $activeMode, '(Legacy) ') ?? [],
                $this->buildGroupedQueue($newQueue, $pendingMode) ?? []
            );
        } else {
            $groupedQueue = $this->buildGroupedQueue($queue, $activeMode);
        }

        $upNext = collect();
        if ($groupedQueue) {
            foreach ($groupedQueue as &$group) {
                $players  = $group['players']->sortBy('queued_at')->values();
                $levelKey = match(true) {
                    str_contains($group['label'], 'Hi')     => 'hi_intermediate',
                    str_contains($group['label'], 'Low')    => 'low_intermediate',
                    str_contains($group['label'], 'Nov')    => 'novice',
                    str_contains($group['label'], 'Winner') => 'winners',
                    str_contains($group['label'], 'Loser')  => 'losers',
                    default => null,
                };

                if ($levelKey && isset($this->levelShuffleSeeds[$levelKey])) {
                    $seed = $this->levelShuffleSeeds[$levelKey];
                    $arr  = $players->all();
                    $len  = count($arr);
                    for ($i = $len - 1; $i > 0; $i--) {
                        $seed = ($seed * 1664525 + 1013904223) & 0x7fffffff;
                        $j    = $seed % ($i + 1);
                        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
                    }
                    $players = collect($arr);
                }

                while ($players->count() >= 4) {
                    $set     = $players->take(4);
                    $players = $players->slice(4)->values();
                    $ids     = $set->pluck('id')->sort()->values()->implode(',');
                    if (in_array($ids, $this->cancelledSets)) {
                        $players = $set->merge($players)->values();
                        break;
                    }
                    $upNext->push([
                        'label'     => $group['label'],
                        'color'     => $group['color'],
                        'level_key' => $levelKey,
                        'players'   => $set,
                        'queued_at' => $set->min('queued_at'),
                        'ready'     => true,
                    ]);
                }
                if ($players->isNotEmpty()) {
                    $upNext->push([
                        'label'     => $group['label'],
                        'color'     => $group['color'],
                        'level_key' => $levelKey,
                        'players'   => $players,
                        'queued_at' => $players->min('queued_at'),
                        'ready'     => false,
                    ]);
                }
                $group['players'] = collect();
            }
            unset($group);
            $upNext = $upNext->sortBy('queued_at')->values();
        }

        return compact('queue', 'groupedQueue', 'upNext', 'requestGroups');
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
            $strict       = $this->game->strict_level;
            $novicesExist = $queue->where('level', 'novice')->isNotEmpty();
            $lowExists    = $queue->where('level', 'low_intermediate')->isNotEmpty();

            $hiPlayers = $queue->where('level', 'hi_intermediate')
                ->filter(fn($p) => $strict || (!($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist)))
                ->filter(fn($p) => $strict || (!($p->play_with_low_intermediate && $lowExists)))
                ->values();
            $placedIds = $hiPlayers->pluck('id');

            $lowPlayers = $queue->where('level', 'low_intermediate')
                ->filter(fn($p) => $strict || (!($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist)))
                ->merge($strict ? collect() : $queue->where('level', 'hi_intermediate')->where('play_with_low_intermediate', true)
                    ->filter(fn($p) => !($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist))
                    ->sortBy('queued_at'))
                ->filter(fn($p) => !$placedIds->contains($p->id))
                ->values();
            $placedIds = $placedIds->merge($lowPlayers->pluck('id'));

            $novicePlayers = $queue->where('level', 'novice')
                ->merge($strict ? collect() : $queue->where('level', '!=', 'novice')->where('play_with_novice', true)->where('novice_play_count', '>', 0)->sortBy('queued_at'))
                ->filter(fn($p) => !$placedIds->contains($p->id))
                ->values();

            return [
                ['label' => $labelPrefix . 'Hi Intermediate',  'color' => 'text-orange-600 dark:text-orange-400', 'players' => $hiPlayers],
                ['label' => $labelPrefix . 'Low Intermediate', 'color' => 'text-yellow-600 dark:text-yellow-400', 'players' => $lowPlayers],
                ['label' => $labelPrefix . 'Novice',           'color' => 'text-blue-600 dark:text-blue-400',     'players' => $novicePlayers],
            ];
        }
        return null;
    }

    public function assignUpNext(array $playerIds): void
    {
        $courts = $this->game->courts()->where('status', 'idle')->where('active', true)->get();
        abort_if($courts->isEmpty(), 403);
        $players = $this->game->players()->whereIn('id', $playerIds)->where('status', 'queued')->get();
        abort_if($players->count() !== count($playerIds), 403);
        $this->createMatch($courts->first(), $players);
    }

    public function cancelUpNext(array $playerIds): void
    {
        sort($playerIds);
        $fingerprint = implode(',', $playerIds);
        if (!in_array($fingerprint, $this->cancelledSets)) {
            $this->cancelledSets[] = $fingerprint;
        }
    }

    public function reshuffleLevel(string $level): void
    {
        abort_unless(in_array($level, ['hi_intermediate', 'low_intermediate', 'novice', 'winners', 'losers']), 403);
        $this->levelShuffleSeeds[$level] = rand();
    }

    public function toggleStrictLevel(): void
    {
        $this->game->update(['strict_level' => !$this->game->strict_level]);
        $this->game->refresh();
        $this->cancelledSets = [];
    }

    public function openRequestGroup(): void
    {
        $this->requestPlayerIds = [];
        $this->dispatch('open-modal', 'request-group');
    }

    public function toggleRequestPlayer(int $playerId): void
    {
        if (in_array($playerId, $this->requestPlayerIds)) {
            $this->requestPlayerIds = array_values(array_filter($this->requestPlayerIds, fn($id) => $id !== $playerId));
        } elseif (count($this->requestPlayerIds) < 4) {
            $this->requestPlayerIds[] = $playerId;
        }
    }

    public function createRequestGroup(): void
    {
        abort_unless(count($this->requestPlayerIds) === 4, 422);

        $alreadyRequestedIds = RequestGroup::with('players')
            ->where('game_id', $this->game->id)
            ->whereIn('status', ['pending', 'ready', 'assigned'])
            ->get()
            ->flatMap(fn($rg) => $rg->players->pluck('id'))
            ->unique();

        $players = $this->game->players()
            ->whereIn('id', $this->requestPlayerIds)
            ->whereIn('status', ['queued', 'playing'])
            ->whereNotIn('id', $alreadyRequestedIds)
            ->get();

        abort_unless($players->count() === 4, 422);

        DB::transaction(function () use ($players) {
            $rg = RequestGroup::create(['game_id' => $this->game->id, 'status' => 'pending']);
            $rg->players()->attach($players->pluck('id'));
            if ($players->every(fn($p) => $p->status === 'queued')) {
                $rg->update(['status' => 'ready']);
            }
        });

        $this->requestPlayerIds = [];
        $this->dispatch('close-modal', 'request-group');
    }

    public function cancelRequestGroup(int $id): void
    {
        $rg = RequestGroup::where('game_id', $this->game->id)
            ->whereIn('status', ['pending', 'ready', 'assigned'])
            ->findOrFail($id);
        $rg->update(['status' => 'cancelled']);
    }

    public function assignRequestGroup(int $id): void
    {
        $rg = RequestGroup::with('players')->where('game_id', $this->game->id)->findOrFail($id);
        abort_unless($rg->isReady(), 403);
        $courts = $this->game->courts()->where('status', 'idle')->where('active', true)->get();
        abort_if($courts->isEmpty(), 403);
        $players = $rg->players()->whereIn('status', ['queued'])->get();
        abort_unless($players->count() === 4, 403);
        $this->createMatch($courts->first(), $players, $rg->id);
        $rg->update(['status' => 'assigned']);
    }

    private function createMatch(Court $court, \Illuminate\Support\Collection $players, ?int $requestGroupId = null): void
    {
        DB::transaction(function () use ($court, $players, $requestGroupId) {
            $ids   = $players->pluck('id')->sort()->values()->implode(',');
            $match = $court->matches()->create([
                'status'            => 'ongoing',
                'group_fingerprint' => $ids,
                'pairing_mode'      => $this->game->pairing_mode,
                'request_group_id'  => $requestGroupId,
            ]);
            $half = (int) ceil($players->count() / 2);
            foreach ($players->values() as $i => $player) {
                $match->matchPlayers()->create(['player_id' => $player->id, 'team' => $i < $half ? 1 : 2]);
                $player->update(['status' => 'playing']);
            }
            $court->update(['status' => 'assigned', 'idled_at' => null]);
        });
    }

    public function openPlayerProfile(int $playerId): void
    {
        $this->viewingPlayerId = $playerId;
        $this->dispatch('open-modal', 'player-profile');
    }
}; ?>

<div wire:poll.3000ms class="min-h-screen py-6 px-4 max-w-lg mx-auto">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $game->name }}</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $game->date->format('M d, Y') }} · Queue</p>
        </div>
        <div class="flex items-center gap-3">
            @if (in_array($game->pairing_mode, ['level', 'win_loss']))
                <button wire:click="openRequestGroup" class="text-sm px-3 py-1.5 rounded-lg border border-purple-300 dark:border-purple-700 text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition font-medium">
                    🎯 Request
                </button>
            @endif
            <span class="flex items-center gap-1.5 text-xs text-green-600 dark:text-green-400">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Live
            </span>
        </div>
    </div>

    {{-- Strict level toggle --}}
    @if ($game->pairing_mode === 'level')
        <div class="flex items-center gap-2 mb-4">
            <button wire:click="toggleStrictLevel" type="button"
                class="relative inline-flex h-4 w-7 items-center rounded-full transition {{ $game->strict_level ? 'bg-wimbledon-600' : 'bg-gray-300 dark:bg-gray-600' }}">
                <span class="inline-block h-3 w-3 transform rounded-full bg-white shadow transition {{ $game->strict_level ? 'translate-x-3.5' : 'translate-x-0.5' }}"></span>
            </button>
            <span class="text-xs text-gray-600 dark:text-gray-400">Same level only</span>
        </div>
    @endif

    {{-- Pending Request Groups --}}
    @if ($requestGroups->where('status', 'pending')->reject(fn($rg) => $rg->isReady())->isNotEmpty())
        <div class="mb-5 space-y-2">
            <p class="text-xs font-semibold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-2">Requested</p>
            @foreach ($requestGroups->where('status', 'pending')->reject(fn($rg) => $rg->isReady()) as $rg)
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-purple-200 dark:border-purple-800 overflow-hidden">
                    <div class="px-4 py-2 border-b border-purple-50 dark:border-purple-900 flex items-center gap-2">
                        <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">🎯 Request</span>
                        <span class="text-xs px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400 font-medium">Waiting</span>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rg->players as $player)
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $player->name }}</span>
                                @if ($player->status === 'playing')
                                    <span class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Playing
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">Queued</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Reshuffle buttons --}}
    @if (in_array($game->pairing_mode, ['level', 'win_loss']))
        @php
            $readyLevels = $upNext->where('ready', true)->pluck('level_key')->filter()->unique()->values();
            $levelButtons = $game->pairing_mode === 'level' ? [
                'hi_intermediate'  => ['label' => 'Hi Int',  'color' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 hover:bg-orange-200'],
                'low_intermediate' => ['label' => 'Low Int', 'color' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 hover:bg-yellow-200'],
                'novice'           => ['label' => 'Novice',  'color' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 hover:bg-blue-200'],
            ] : [
                'winners' => ['label' => 'Winners', 'color' => 'bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/30 dark:text-wimbledon-400 hover:bg-wimbledon-200'],
                'losers'  => ['label' => 'Losers',  'color' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 hover:bg-red-200'],
            ];
        @endphp
        @if ($readyLevels->isNotEmpty())
            <div class="flex items-center gap-1.5 mb-4">
                @foreach ($levelButtons as $levelKey => $btn)
                    @if ($readyLevels->contains($levelKey))
                        <button wire:click="reshuffleLevel('{{ $levelKey }}')"
                            class="flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg font-medium transition {{ $btn['color'] }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            {{ $btn['label'] }}
                        </button>
                    @endif
                @endforeach
            </div>
        @endif
    @endif

    {{-- Up Next --}}
    @if ($upNext->isNotEmpty() || $requestGroups->where('status', 'ready')->isNotEmpty())
        <div class="mb-5 space-y-2">
            <p class="text-xs font-semibold text-wimbledon-600 dark:text-wimbledon-400 uppercase tracking-wider mb-2">Up Next</p>

            @foreach ($upNext as $set)
                @php $ids = $set['players']->pluck('id')->toArray(); @endphp
                <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $set['ready'] ? 'border-wimbledon-300 dark:border-wimbledon-700' : 'border-gray-200 dark:border-gray-700' }} overflow-hidden">
                    <div class="px-4 py-2 border-b {{ $set['ready'] ? 'border-wimbledon-100 dark:border-wimbledon-800' : 'border-gray-100 dark:border-gray-700' }} flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold {{ $set['color'] }}">{{ $set['label'] }}</span>
                            <span class="text-xs text-gray-400" x-data="timer({{ $set['queued_at']->timestamp }})" x-text="elapsed"></span>
                        </div>
                        @if ($set['ready'])
                            <button wire:click="assignUpNext({{ json_encode($ids) }})"
                                class="text-xs px-2.5 py-1 rounded-lg bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-300 hover:bg-wimbledon-200 transition font-medium">
                                → Assign to Court
                            </button>
                        @else
                            <span class="text-xs text-gray-400">{{ $set['players']->count() }}/4 waiting</span>
                        @endif
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($set['players'] as $i => $player)
                            @php
                                $levelColor = match($player->level) {
                                    'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                    'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                    'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                };
                            @endphp
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400 w-4">{{ $i + 1 }}</span>
                                    <div>
                                        <button wire:click="openPlayerProfile({{ $player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $player->name }}</button>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                                            <span class="text-xs text-gray-400 capitalize">{{ $player->gender }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-xs text-right space-y-0.5">
                                    <div>
                                        <span class="text-green-600 dark:text-green-400">{{ $player->wins }}W</span>
                                        <span class="text-gray-400 mx-0.5">/</span>
                                        <span class="text-red-500 dark:text-red-400">{{ $player->losses }}L</span>
                                    </div>
                                    @if ($player->queued_at)
                                        <div x-data="timer({{ $player->queued_at->timestamp }})" x-text="elapsed" class="font-mono text-gray-400 dark:text-gray-500"></div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- Ready request groups --}}
            @foreach ($requestGroups->where('status', 'ready') as $rg)
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-purple-400 dark:border-purple-600 overflow-hidden">
                    <div class="px-4 py-2 border-b border-purple-100 dark:border-purple-800 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">🎯 Request</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-600 dark:bg-purple-900/40 dark:text-purple-400 font-medium">Ready</span>
                        </div>
                        <button wire:click="assignRequestGroup({{ $rg->id }})" class="text-xs px-2.5 py-1 rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 hover:bg-purple-200 transition font-medium">
                            → Assign to Court
                        </button>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rg->players as $player)
                            @php
                                $levelColor = match($player->level) {
                                    'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                    'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                    'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                };
                            @endphp
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $player->name }}</span>
                                    <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                                </div>
                                <span class="text-xs text-gray-400">Queued</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Plain queue (random mode) --}}
    @if (!$groupedQueue && $queue->isNotEmpty())
        <div class="mb-5">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Queue <span class="text-gray-400">({{ $queue->count() }})</span></p>
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($queue as $i => $player)
                    @php
                        $levelColor = match($player->level) {
                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                        };
                    @endphp
                    <div class="flex items-center justify-between px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400 w-5">{{ $i + 1 }}</span>
                            <div>
                                <button wire:click="openPlayerProfile({{ $player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $player->name }}</button>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                                    <span class="text-xs text-gray-400 capitalize">{{ $player->gender }}</span>
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
    @endif

    @if ($queue->isEmpty() && $upNext->isEmpty() && $requestGroups->whereIn('status', ['pending','ready'])->isEmpty())
        <div class="text-center py-16 text-sm text-gray-400 dark:text-gray-500">No players in queue</div>
    @endif

    {{-- Request Group Modal --}}
    <x-modal name="request-group" maxWidth="sm">
        <div class="p-6" x-data="{ search: '' }" x-on:open-modal.window="if ($event.detail === 'request-group') search = ''">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">🎯 Create Request Group</h3>
                <button x-on:click="$dispatch('close-modal', 'request-group')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Select exactly 4 players.</p>
            @if (count($requestPlayerIds) > 0)
                @php $selectedPlayers = $game->players()->whereIn('id', $requestPlayerIds)->orderByRaw('FIELD(id, ' . implode(',', $requestPlayerIds) . ')')->get(); @endphp
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach ($selectedPlayers as $sp)
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 font-medium">
                            {{ $sp->name }}
                            <button wire:click="toggleRequestPlayer({{ $sp->id }})" class="hover:text-red-500 transition leading-none">&times;</button>
                        </span>
                    @endforeach
                    @for ($i = count($requestPlayerIds); $i < 4; $i++)
                        <span class="inline-flex items-center text-xs px-2 py-1 rounded-full border border-dashed border-gray-300 dark:border-gray-600 text-gray-400">Empty</span>
                    @endfor
                </div>
            @endif
            <div class="flex items-center gap-2 mb-3">
                <input x-model="search" type="text" placeholder="Search by name…"
                    class="flex-1 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-wimbledon-500" />
                <span class="text-xs font-medium {{ count($requestPlayerIds) === 4 ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400' }}">{{ count($requestPlayerIds) }}/4</span>
            </div>
            @php
                $alreadyRequestedPlayerIds = RequestGroup::with('players')
                    ->where('game_id', $game->id)
                    ->whereIn('status', ['pending', 'ready', 'assigned'])
                    ->get()
                    ->flatMap(fn($rg) => $rg->players->pluck('id'))
                    ->unique();
                $allSelectablePlayers = $game->players()
                    ->whereIn('status', ['queued', 'playing'])
                    ->orderBy('name')
                    ->get();
            @endphp
            <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-72 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 mb-4">
                @forelse ($allSelectablePlayers as $player)
                    @php
                        $selected   = in_array($player->id, $requestPlayerIds);
                        $inOtherRg  = !$selected && $alreadyRequestedPlayerIds->contains($player->id);
                        $disabled   = $inOtherRg || (!$selected && count($requestPlayerIds) >= 4);
                        $levelColor = match($player->level) {
                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                        };
                    @endphp
                    <button wire:click="{{ $disabled ? '' : 'toggleRequestPlayer(' . $player->id . ')' }}"
                        x-show="search === '' || '{{ strtolower($player->name) }}'.includes(search.toLowerCase())"
                        {{ $disabled ? 'disabled' : '' }}
                        class="w-full flex items-center justify-between px-4 py-3 transition text-left {{ $selected ? 'bg-purple-50 dark:bg-purple-900/20' : '' }} {{ $disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                        <div class="flex items-center gap-2">
                            <span class="w-4 h-4 rounded border flex items-center justify-center flex-shrink-0 {{ $selected ? 'bg-purple-600 border-purple-600' : 'border-gray-300 dark:border-gray-600' }}">
                                @if ($selected)
                                    <svg class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                @endif
                            </span>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $player->name }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($inOtherRg)
                                <span class="text-xs text-purple-500 dark:text-purple-400">Requested</span>
                            @elseif ($player->status === 'playing')
                                <span class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Playing
                                </span>
                            @else
                                <span class="text-xs text-gray-400">Queued</span>
                            @endif
                        </div>
                    </button>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500">No available players</p>
                @endforelse
            </div>
            <button wire:click="createRequestGroup"
                {{ count($requestPlayerIds) === 4 ? '' : 'disabled' }}
                class="w-full justify-center inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest transition {{ count($requestPlayerIds) !== 4 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-700 dark:hover:bg-white' }}">
                Create Request Group
            </button>
        </div>
    </x-modal>

    {{-- Player Profile Modal --}}
    <x-modal name="player-profile" maxWidth="md">
        <div class="p-6 max-h-[80vh] overflow-y-auto">
            @if ($viewingPlayerId)
                @php $vp = $game->players()->with('matchPlayers.gameMatch')->find($viewingPlayerId); @endphp
                @if ($vp)
                    @php
                        $vpLevelColor = match($vp->level) {
                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                        };
                        $vpGames  = $vp->wins + $vp->losses;
                        $vpWinPct = $vpGames > 0 ? round($vp->wins / $vpGames * 100) : 0;
                    @endphp
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $vp->name }}</h3>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs px-1.5 py-0.5 rounded-full {{ $vpLevelColor }}">{{ $vp->level_label }}</span>
                                <span class="text-xs text-gray-400 capitalize">{{ $vp->gender }}</span>
                            </div>
                        </div>
                        <button x-on:click="$dispatch('close-modal', 'player-profile')" class="text-gray-400 hover:text-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ $vp->wins }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">Wins</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-red-500 dark:text-red-400">{{ $vp->losses }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">Losses</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-gray-700 dark:text-gray-300">{{ $vpWinPct }}%</p>
                            <p class="text-xs text-gray-500 mt-0.5">Win %</p>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </x-modal>

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
