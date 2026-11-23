<?php

use App\Models\Court;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\RequestGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Game $game;

    // Manual assignment state
    public ?int $selectedPlayerId = null;
    public ?int $assignCourtId = null;
    public int $assignTeam = 1;

    // Finish match modal state
    public ?int $finishingMatchId = null;
    public ?int $finishingCourtId = null;
    public string $finishCourtLabel = '';
    public int $team1_score = 0;
    public int $team2_score = 0;
    public bool $winnersStay = false;

    // Rest player modal state
    public ?int $restingMatchPlayerId = null;
    public string $restingPlayerName = '';

    // Stats modal
    public bool $showStats = false;
    public bool $queueDetached = false;

    // Player profile modal
    public ?int $viewingPlayerId = null;

    // Per-level shuffle seeds — changing triggers a re-deal within that level pool
    public array $levelShuffleSeeds = [];

    // Cancelled Up Next sets — array of sorted player ID fingerprints
    public array $cancelledSets = [];

    // Request group modal
    public array $requestPlayerIds = [];

    // Player list modal
    public string $playerListSearch = '';
    public array $selectedPlayerIds = [];

    // Switch player modal
    public ?int $switchMatchPlayerId = null;
    public string $switchPlayerName = '';
    public int $switchTeam = 1;
    public array $switchOtherTeamPlayers = [];

    // Confirm modal
    public string $confirmTitle = '';
    public string $confirmMessage = '';
    public ?int $skipCourtId = null;

    public function openSkipConfirm(int $courtId): void
    {
        $this->skipCourtId = $courtId;
        $this->dispatch('open-modal', 'confirm-action');
    }

    public function runSkipCourt(): void
    {
        $this->dispatch('close-modal', 'confirm-action');
        $this->skipCourt($this->skipCourtId);
        $this->skipCourtId = null;
    }

    // Pairing mode switch modal
    public string $pendingSwitchMode = '';

    public function openPairingSwitch(string $mode): void
    {
        if ($mode === $this->game->pairing_mode) return;
        if ($this->game->players()->where('status', 'queued')->count() === 0) {
            $this->updatePairingMode($mode);
            return;
        }
        $this->pendingSwitchMode = $mode;
        $this->dispatch('open-modal', 'switch-pairing');
    }

    public function deferPairingMode(string $mode): void
    {
        // Mark the new mode as pending; existing queued players keep queued_mode = current mode
        $this->game->update(['pending_pairing_mode' => $mode]);

        // Stamp queued_mode on all currently queued players so they stay in old-mode grouping
        $needed = 4; // doubles default; orphan check uses group logic below
        $queue  = $this->game->players()->where('status', 'queued')->whereNull('queued_mode')->get();

        // Group by old mode to find orphans
        $oldMode = $this->game->pairing_mode;
        $groups  = match($oldMode) {
            'win_loss' => [
                $queue->filter(fn($p) => $p->lastResult() !== 'loss')->values(),
                $queue->filter(fn($p) => $p->lastResult() === 'loss')->values(),
            ],
            'level' => [
                $queue->where('level', 'hi_intermediate')->values(),
                $queue->where('level', 'low_intermediate')->values(),
                $queue->where('level', 'novice')->values(),
            ],
            default => [$queue],
        };

        $orphanIds  = collect();
        $legacyIds  = collect();

        foreach ($groups as $group) {
            $complete = (int) floor($group->count() / $needed) * $needed;
            $legacyIds  = $legacyIds->merge($group->take($complete)->pluck('id'));
            $orphanIds  = $orphanIds->merge($group->slice($complete)->pluck('id'));
        }

        // Legacy players: tag with old mode so they stay in old grouping
        if ($legacyIds->isNotEmpty()) {
            $this->game->players()->whereIn('id', $legacyIds)->update(['queued_mode' => $oldMode]);
        }

        // Orphans: move to new mode queue (clear queued_mode, reset queued_at)
        if ($orphanIds->isNotEmpty()) {
            $this->game->players()->whereIn('id', $orphanIds)->update([
                'queued_mode' => $mode,
                'queued_at'   => now(),
            ]);
        }

        $this->pendingSwitchMode = '';
        $this->dispatch('close-modal', 'switch-pairing');
    }

    public function mount(Game $game): void
    {
        abort_unless(Auth::user()->games()->where('id', $game->id)->exists(), 403);
        $this->game = $game;
    }

    public function with(): array
    {
        $this->game->refresh();

        $courts = $this->game->courts()
            ->with(['currentMatch.matchPlayers.player'])
            ->get();

        // Load active request groups first so their players can be excluded from the general queue
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

        // If a pairing transition is in progress, check if legacy queue is drained
        if ($this->game->pending_pairing_mode) {
            $legacyRemaining = $queue->whereNotNull('queued_mode')
                ->where('queued_mode', $this->game->pairing_mode)
                ->count();
            if ($legacyRemaining === 0) {
                // All legacy players played — complete the switch
                $this->game->update([
                    'pairing_mode'         => $this->game->pending_pairing_mode,
                    'pending_pairing_mode' => null,
                ]);
                $this->game->players()->where('status', 'queued')->update(['queued_mode' => null]);
                $this->game->refresh();
                $queue = $this->game->players()->where('status', 'queued')->oldest()->get();
            }
        }

        $activeMode = $this->game->pairing_mode;
        $pendingMode = $this->game->pending_pairing_mode;

        // During transition: legacy players use old mode grouping, new-mode players use pending mode
        if ($pendingMode) {
            $legacyQueue = $queue->where('queued_mode', $activeMode)->values();
            $newQueue    = $queue->where('queued_mode', '!=', $activeMode)->values();

            $groupedQueue = $this->buildGroupedQueue($legacyQueue, $activeMode, '(Legacy) ');
            $newGrouped   = $this->buildGroupedQueue($newQueue, $pendingMode);
            $groupedQueue = array_merge($groupedQueue ?? [], $newGrouped ?? []);
        } else {
            $groupedQueue = $this->buildGroupedQueue($queue, $activeMode);
        }

        // Pre-compute max group size per pairing mode for Mix & Fill visibility
        $completeGroupSizes = $groupedQueue
            ? collect($groupedQueue)->map(fn($g) => $g['players']->count())->max() ?? 0
            : 0;

        // Build "Up Next" sets: groups of 4 from any sub-queue
        // In level mode, shuffle the pool using per-level seed so reshuffle re-deals players across sets
        $upNext = collect();
        if ($groupedQueue) {
            foreach ($groupedQueue as &$group) {
                $players = $group['players']->sortBy('queued_at')->values();

                // Determine group key from label to look up shuffle seed
                $levelKey = match(true) {
                    str_contains($group['label'], 'Hi')      => 'hi_intermediate',
                    str_contains($group['label'], 'Low')     => 'low_intermediate',
                    str_contains($group['label'], 'Nov')     => 'novice',
                    str_contains($group['label'], 'Winner')  => 'winners',
                    str_contains($group['label'], 'Loser')   => 'losers',
                    default => null,
                };

                if ($levelKey && isset($this->levelShuffleSeeds[$levelKey])) {
                    // Shuffle using the stored seed so the same seed always produces the same order
                    $seed = $this->levelShuffleSeeds[$levelKey];
                    $arr  = $players->all();
                    $len  = count($arr);
                    for ($i = $len - 1; $i > 0; $i--) {
                        $seed = ($seed * 1664525 + 1013904223) & 0x7fffffff;
                        $j = $seed % ($i + 1);
                        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
                    }
                    $players = collect($arr);
                }

                while ($players->count() >= 4) {
                    $set     = $players->take(4);
                    $players = $players->slice(4)->values();
                    $ids = $set->pluck('id')->sort()->values()->implode(',');
                    if (in_array($ids, $this->cancelledSets)) {
                        // Cancelled — put players back into the remaining pool
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

        // Merge ready request groups into upNext so they sort by queued_at alongside regular sets
        foreach ($requestGroups->where('status', 'ready') as $rg) {
            $players = $rg->players->filter(fn($p) => $p->status === 'queued')->values();
            if ($players->isEmpty()) continue;
            $upNext->push([
                'label'      => '🎯 Request',
                'color'      => 'text-purple-600 dark:text-purple-400',
                'level_key'  => null,
                'players'    => $players,
                'queued_at'  => $players->min('queued_at'),
                'ready'      => true,
                'request_group_id' => $rg->id,
            ]);
        }
        $upNext = $upNext->sortBy('queued_at')->values();

        // Detect courts where the current group has played together before
        $repeatCourtIds = $courts->filter(function ($court) {
            $match = $court->currentMatch;
            if (!$match || !$match->group_fingerprint) return false;
            return DB::table('matches')
                ->join('courts', 'matches.court_id', '=', 'courts.id')
                ->where('courts.game_id', $this->game->id)
                ->where('matches.group_fingerprint', $match->group_fingerprint)
                ->where('matches.id', '!=', $match->id)
                ->where('matches.status', 'finished')
                ->exists();
        })->pluck('id')->flip()->all();

        return compact('courts', 'queue', 'groupedQueue', 'upNext', 'completeGroupSizes', 'repeatCourtIds', 'requestGroups');
    }

    public function updatePairingMode(string $mode): void
    {
        $this->game->update([
            'pairing_mode'         => $mode,
            'pending_pairing_mode' => null,
        ]);
        $this->game->players()->where('status', 'queued')->update(['queued_mode' => null]);
        $this->game->refresh();
        $this->pendingSwitchMode = '';
        $this->dispatch('close-modal', 'switch-pairing');
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
                ['label' => $labelPrefix . 'Novice',           'color' => 'text-blue-600 dark:text-blue-400',    'players' => $novicePlayers],
            ];
        }

        return null;
    }

    public function assignUpNext(array $playerIds): void
    {
        $courts = $this->game->courts()->where('status', 'idle')->where('active', true)->get();
        abort_if($courts->isEmpty(), 403);

        $court   = $courts->first();
        $players = $this->game->players()
            ->whereIn('id', $playerIds)
            ->where('status', 'queued')
            ->get();

        abort_if($players->count() !== count($playerIds), 403);
        $this->createMatch($court, $players);
    }

    // Toggle court type between singles/doubles
    public function addCourt(): void
    {
        $next = ($this->game->courts()->max('court_number') ?? 0) + 1;
        $code = strtoupper(\Illuminate\Support\Str::random(6));
        while (\App\Models\Court::where('code', $code)->exists()) {
            $code = strtoupper(\Illuminate\Support\Str::random(6));
        }
        $this->game->courts()->create([
            'court_number' => $next,
            'type'         => 'doubles',
            'status'       => 'idle',
            'idled_at'     => now(),
            'code'         => $code,
        ]);
        $this->game->increment('number_of_courts');
    }

    public function toggleCourtActive(Court $court): void
    {
        abort_unless($court->game_id === $this->game->id, 403);
        abort_if($court->isPlaying() || $court->isAssigned(), 403);
        $court->update(['active' => !$court->active]);
    }

    public function toggleCourtType(Court $court): void
    {
        abort_unless($court->game_id === $this->game->id, 403);
        abort_if($court->isPlaying(), 403);
        $court->update(['type' => $court->type === 'doubles' ? 'singles' : 'doubles']);
    }

    public function autoAssignCourt(Court $court): void
    {
        abort_unless($court->game_id === $this->game->id, 403);
        abort_if(!$court->isIdle(), 403);
        abort_unless($court->isActive(), 403);

        $needed = $court->type === 'doubles' ? 4 : 2;
        $queue  = $this->game->players()->where('status', 'queued')->oldest()->get();

        if ($queue->count() < $needed) return;

        $selected = $this->pickPlayers($queue, $needed, $court->type);
        if ($selected->count() < $needed) return;

        $this->createMatch($court, $selected);
    }

    public function mixAndFillCourt(int $courtId): void
    {
        $court = Court::findOrFail($courtId);
        abort_unless($court->game_id === $this->game->id, 403);
        abort_if(!$court->isIdle(), 403);

        $needed = $court->type === 'doubles' ? 4 : 2;
        $queue  = $this->game->players()->where('status', 'queued')->oldest()->get();

        if ($queue->count() < $needed) return;

        $selected = $this->pickPlayers($queue, $needed, $court->type, forceMix: true);
        if ($selected->count() < $needed) return;

        $this->createMatch($court, $selected);
    }

    // Auto-assign players to all idle courts
    public function autoAssign(): void
    {
        $courts = $this->game->courts()->where('status', 'idle')->where('active', true)->get();
        $queue  = $this->game->players()->where('status', 'queued')->oldest()->get();

        foreach ($courts as $court) {
            $needed = $court->type === 'doubles' ? 4 : 2;
            if ($queue->count() < $needed) break;

            $selected = $this->pickPlayers($queue, $needed, $court->type);
            if ($selected->count() < $needed) continue;

            $this->createMatch($court, $selected);
            $queue = $queue->filter(fn($p) => !$selected->contains('id', $p->id))->values();
        }
    }

    private function pickPlayers($queue, int $needed, string $courtType, bool $forceMix = false): \Illuminate\Support\Collection
    {
        $mode    = $this->game->pairing_mode;
        $coPlays = $this->coPlayCounts($queue->pluck('id')->all());

        if ($mode === 'win_loss') {
            $winners = $queue->filter(fn($p) => $p->lastResult() !== 'loss')->values();
            $losers  = $queue->filter(fn($p) => $p->lastResult() === 'loss')->values();

            if (!$forceMix) {
                $thresholdMinutes = $this->game->queue_wait_threshold;
                $thresholdTime    = now()->subMinutes($thresholdMinutes);

                foreach ([$winners, $losers] as $group) {
                    if ($group->count() >= $needed) {
                        return $this->applyNoviceFilter($group, $needed, $coPlays);
                    }
                    $oldest = $group->sortBy('queued_at')->first();
                    if ($oldest && $oldest->queued_at && $oldest->queued_at->lt($thresholdTime)) {
                        $forceMix = true;
                        break;
                    }
                }
            }

            if ($forceMix) {
                $sorted = $winners->merge($losers)->sortBy('queued_at')->values();
                return $this->applyNoviceFilter($sorted, $needed, $coPlays);
            }

            return collect();
        } elseif ($mode === 'level') {
            $strict       = $this->game->strict_level;
            $novicesExist = $queue->where('level', 'novice')->isNotEmpty();
            $lowExists    = $queue->where('level', 'low_intermediate')->isNotEmpty();

            // Pure hi_intermediate group
            $hiGroup = $queue->where('level', 'hi_intermediate')
                ->filter(fn($p) => $strict || (!($p->play_with_low_intermediate && $lowExists)))
                ->filter(fn($p) => $strict || (!($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist)))
                ->values();
            if ($hiGroup->count() >= $needed) {
                return $this->applyNoviceFilter($hiGroup, $needed, $coPlays);
            }

            // Low intermediate group — pull in willing hi_intermediate players to fill (unless strict)
            $lowGroup = $queue->where('level', 'low_intermediate')
                ->filter(fn($p) => $strict || (!($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist)))
                ->values();
            if ($lowGroup->isNotEmpty()) {
                $willingHi = $strict ? collect() : $queue->where('level', 'hi_intermediate')
                    ->where('play_with_low_intermediate', true)
                    ->filter(fn($p) => !($p->play_with_novice && $p->novice_play_count > 0 && $novicesExist))
                    ->sortBy('queued_at')->values();
                $combined = $lowGroup->merge($willingHi);
                if ($combined->count() >= $needed) {
                    return $this->applyNoviceFilter($combined, $needed, $coPlays);
                }
            }

            // Novice group — pull in willing non-novice players to fill (unless strict)
            $novices = $queue->where('level', 'novice')->values();
            if ($novices->isNotEmpty()) {
                $willing = $strict ? collect() : $queue->where('level', '!=', 'novice')
                    ->where('play_with_novice', true)
                    ->where('novice_play_count', '>', 0)
                    ->sortBy('queued_at')->values();
                $combined = $novices->merge($willing);
                if ($combined->count() >= $needed) {
                    return $this->applyNoviceFilter($combined, $needed, $coPlays);
                }
            }

            $levelOrder = ['hi_intermediate' => 0, 'low_intermediate' => 1, 'novice' => 2];
            $sorted = $queue->sortBy(fn($p) => $levelOrder[$p->level] ?? 9);
        } else {
            $sorted = $queue->shuffle();
        }

        return $this->applyNoviceFilter($sorted, $needed, $coPlays);
    }

    /**
     * Returns map: playerId => [coPlayerId => timesPlayedTogether]
     * Only considers players currently in the candidate pool.
     */
    private function coPlayCounts(array $playerIds): array
    {
        if (count($playerIds) < 2) return [];

        // Get all match_ids these players have been in together (within this game)
        $rows = DB::table('match_players as a')
            ->join('match_players as b', 'a.match_id', '=', 'b.match_id')
            ->join('matches', 'a.match_id', '=', 'matches.id')
            ->join('courts', 'matches.court_id', '=', 'courts.id')
            ->where('courts.game_id', $this->game->id)
            ->whereIn('a.player_id', $playerIds)
            ->whereIn('b.player_id', $playerIds)
            ->where('a.player_id', '!=', DB::raw('b.player_id'))
            ->select('a.player_id', 'b.player_id as co_player_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->player_id][$row->co_player_id] = ($map[$row->player_id][$row->co_player_id] ?? 0) + 1;
        }
        return $map;
    }

    private function applyNoviceFilter($sorted, int $needed, array $coPlays = []): \Illuminate\Support\Collection
    {
        $picked = collect();
        $candidates = $sorted->values();

        while ($picked->count() < $needed && $candidates->isNotEmpty()) {
            // Score each remaining candidate: sum of co-play counts with already-picked players
            // Lower score = played together less = preferred
            $pickedIds = $picked->pluck('id')->all();

            $next = $candidates->sortBy(function ($player) use ($pickedIds, $coPlays) {
                $score = 0;
                foreach ($pickedIds as $pid) {
                    $score += $coPlays[$player->id][$pid] ?? 0;
                }
                return $score;
            })->first();

            // Novice guard: skip if no willing player available
            if ($next->level === 'novice') {
                $hasWilling = $picked->where('play_with_novice', true)->isNotEmpty()
                    || $candidates->where('play_with_novice', true)->whereNotIn('id', $pickedIds)->where('id', '!=', $next->id)->isNotEmpty();
                if (!$hasWilling && $picked->isNotEmpty()) {
                    $candidates = $candidates->reject(fn($p) => $p->id === $next->id)->values();
                    continue;
                }
            }

            $picked->push($next);
            $candidates = $candidates->reject(fn($p) => $p->id === $next->id)->values();
        }

        if ($picked->count() < $needed) {
            $picked = $sorted->take($needed);
        }
        return $picked->take($needed);
    }

    private function createMatch(Court $court, \Illuminate\Support\Collection $players, ?int $requestGroupId = null): void
    {
        DB::transaction(function () use ($court, $players, $requestGroupId) {
            $ids = $players->pluck('id')->sort()->values()->implode(',');
            $match = $court->matches()->create([
                'status'            => 'ongoing',
                'group_fingerprint' => $ids,
                'pairing_mode'      => $this->game->pairing_mode,
                'request_group_id'  => $requestGroupId,
            ]);

            $half = (int) ceil($players->count() / 2);
            foreach ($players->values() as $i => $player) {
                $match->matchPlayers()->create([
                    'player_id' => $player->id,
                    'team'      => $i < $half ? 1 : 2,
                ]);
                $player->update(['status' => 'playing']);
            }

            $court->update(['status' => 'assigned', 'idled_at' => null]);
        });
    }

    public function startMatch(int $courtId): void
    {
        $court = Court::findOrFail($courtId);
        abort_unless($court->game_id === $this->game->id, 403);
        $court->currentMatch?->update(['started_at' => now()]);
        $court->update(['status' => 'playing']);
    }

    public function reshuffleCourt(int $courtId): void
    {
        $court = Court::with('currentMatch.matchPlayers.player')->findOrFail($courtId);
        abort_unless($court->game_id === $this->game->id, 403);
        abort_unless($court->isAssigned(), 403);

        DB::transaction(function () use ($court) {
            $players = $court->currentMatch->matchPlayers->map->player;
            $court->currentMatch->delete();

            // Put players back at front of queue with an earlier timestamp so they get picked first
            $now = now();
            foreach ($players as $player) {
                $player->update(['status' => 'queued', 'queued_at' => $now]);
            }
            $court->update(['status' => 'idle', 'idled_at' => $now]);
        });

        // Re-assign with freshness logic naturally deprioritising the same group
        $court->refresh();
        $this->autoAssignCourt($court);
    }

    public function reshuffleLevel(string $level): void
    {
        abort_unless($this->game->pairing_mode === 'level' || $this->game->pairing_mode === 'win_loss', 403);
        abort_unless(in_array($level, ['hi_intermediate', 'low_intermediate', 'novice', 'winners', 'losers']), 403);
        $this->levelShuffleSeeds[$level] = rand();
    }

    public function cancelUpNext(array $playerIds): void
    {
        sort($playerIds);
        $fingerprint = implode(',', $playerIds);
        if (!in_array($fingerprint, $this->cancelledSets)) {
            $this->cancelledSets[] = $fingerprint;
        }
    }

    public function toggleStrictLevel(): void
    {
        $this->game->update(['strict_level' => !$this->game->strict_level]);
        $this->game->refresh();
        $this->cancelledSets = [];
    }

    public function openPlayerProfile(int $playerId): void
    {
        $this->viewingPlayerId = $playerId;
        $this->dispatch('open-modal', 'player-profile');
    }

    public function openStats(): void
    {
        $this->showStats = true;
        $this->dispatch('open-modal', 'game-stats');
    }

    public function openHistory(): void
    {
        $this->dispatch('open-modal', 'match-history');
    }

    public function skipCourt(int $courtId): void
    {
        $court = Court::with('currentMatch.matchPlayers.player')->findOrFail($courtId);
        abort_unless($court->game_id === $this->game->id, 403);
        abort_unless($court->isAssigned(), 403);

        DB::transaction(function () use ($court) {
            $match = $court->currentMatch;
            $rgId  = $match->request_group_id;

            $now = $this->endOfQueueTimestamp();

            foreach ($match->matchPlayers as $mp) {
                $mp->player->update(['status' => 'queued', 'queued_at' => $now]);
            }
            $match->delete();
            $court->update(['status' => 'idle', 'idled_at' => now()]);

            // If this match came from a request group, restore it to ready at end of queue
            if ($rgId) {
                RequestGroup::where('id', $rgId)
                    ->where('game_id', $this->game->id)
                    ->update(['status' => 'ready']);
            }
        });
    }

    // Manual: select a queued player then assign to a court/team
    public function selectPlayer(int $playerId): void
    {
        $this->selectedPlayerId = $this->selectedPlayerId === $playerId ? null : $playerId;
    }

    public function openManualAssign(int $courtId, int $team): void
    {
        $this->assignCourtId = $courtId;
        $this->assignTeam    = $team;
        $this->dispatch('open-modal', 'manual-assign');
    }

    public function assignPlayer(int $playerId): void
    {
        $court  = Court::findOrFail($this->assignCourtId);
        $player = Player::findOrFail($playerId);

        abort_unless($court->game_id === $this->game->id, 403);
        abort_unless($player->game_id === $this->game->id && $player->isQueued(), 403);

        $match = $court->currentMatch ?? $court->matches()->create([
            'status'     => 'ongoing',
            'started_at' => now(),
        ]);

        $match->matchPlayers()->create(['player_id' => $playerId, 'team' => $this->assignTeam]);
        $player->update(['status' => 'playing']);

        $needed = $court->type === 'doubles' ? 4 : 2;
        if ($match->matchPlayers()->count() >= $needed) {
            $court->update(['status' => 'assigned']);
        }

        $this->assignCourtId = null;
        $this->dispatch('close-modal', 'manual-assign');
    }

    public function openRestModal(int $matchPlayerId): void
    {
        $mp = MatchPlayer::with('player')->findOrFail($matchPlayerId);
        abort_unless($mp->gameMatch->court->game_id === $this->game->id, 403);
        $this->restingMatchPlayerId = $matchPlayerId;
        $this->restingPlayerName    = $mp->player->name;
        $this->dispatch('open-modal', 'rest-player');
    }

    public function restPlayer(?int $replacementPlayerId = null): void
    {
        $mp = MatchPlayer::with(['player', 'gameMatch.court'])->findOrFail($this->restingMatchPlayerId);
        abort_unless($mp->gameMatch->court->game_id === $this->game->id, 403);

        $court = $mp->gameMatch->court;

        DB::transaction(function () use ($mp, $court, $replacementPlayerId) {
            $mp->player->update(['status' => 'queued', 'queued_at' => now()]);

            if ($replacementPlayerId) {
                $replacement = Player::findOrFail($replacementPlayerId);
                abort_unless($replacement->game_id === $this->game->id && $replacement->isQueued(), 403);
                $mp->update(['player_id' => $replacementPlayerId]);
                $replacement->update(['status' => 'playing']);
            } else {
                $mp->delete();
                $remaining = $mp->gameMatch->matchPlayers()->count();
                if ($remaining === 0) {
                    $mp->gameMatch->delete();
                    $court->update(['status' => 'idle', 'idled_at' => now()]);
                } else {
                    $court->update(['status' => 'assigned', 'idled_at' => null]);
                }
            }
        });

        $this->restingMatchPlayerId = null;
        $this->dispatch('close-modal', 'rest-player');
    }

    public function openSwitchPlayer(int $matchPlayerId): void
    {
        $mp = MatchPlayer::with(['player', 'gameMatch.matchPlayers.player'])->findOrFail($matchPlayerId);
        abort_unless($mp->gameMatch->court->game_id === $this->game->id, 403);
        $this->switchMatchPlayerId   = $matchPlayerId;
        $this->switchPlayerName      = $mp->player->name;
        $this->switchTeam            = $mp->team;
        $this->switchOtherTeamPlayers = $mp->gameMatch->matchPlayers
            ->where('team', '!=', $mp->team)
            ->map(fn($other) => ['id' => $other->id, 'name' => $other->player->name, 'level_label' => $other->player->level_label, 'gender' => $other->player->gender])
            ->values()->toArray();
        $this->dispatch('open-modal', 'switch-player');
    }

    public function switchPlayer(int $targetMatchPlayerId): void
    {
        $mp     = MatchPlayer::with(['player', 'gameMatch.court'])->findOrFail($this->switchMatchPlayerId);
        $target = MatchPlayer::with('player')->findOrFail($targetMatchPlayerId);

        abort_unless($mp->gameMatch->court->game_id === $this->game->id, 403);
        abort_unless($target->game_match_id === $mp->game_match_id, 403);

        DB::transaction(function () use ($mp, $target) {
            $mpPlayerId     = $mp->player_id;
            $mp->update(['player_id' => $target->player_id]);
            $target->update(['player_id' => $mpPlayerId]);
        });

        $this->switchMatchPlayerId    = null;
        $this->switchOtherTeamPlayers = [];
        $this->dispatch('close-modal', 'switch-player');
    }

    public function switchPlayerFromQueue(int $playerId): void
    {
        $mp          = MatchPlayer::with(['player', 'gameMatch.court'])->findOrFail($this->switchMatchPlayerId);
        $replacement = Player::findOrFail($playerId);

        abort_unless($mp->gameMatch->court->game_id === $this->game->id, 403);
        abort_unless($replacement->game_id === $this->game->id && $replacement->isQueued(), 403);

        DB::transaction(function () use ($mp, $replacement) {
            $mp->player->update(['status' => 'queued', 'queued_at' => now()]);
            $mp->update(['player_id' => $replacement->id]);
            $replacement->update(['status' => 'playing']);
        });

        $this->switchMatchPlayerId    = null;
        $this->switchOtherTeamPlayers = [];
        $this->dispatch('close-modal', 'switch-player');
    }

    // Open finish match modal
    public function openFinishMatch(int $courtId): void
    {
        $court = Court::with('currentMatch.matchPlayers.player')->findOrFail($courtId);
        abort_unless($court->game_id === $this->game->id, 403);

        $this->finishingCourtId = $courtId;
        $this->finishingMatchId = $court->currentMatch?->id;
        $this->finishCourtLabel = 'Court ' . $court->court_number;
        $this->team1_score      = 0;
        $this->team2_score      = 0;
        $this->winnersStay      = $this->game->winners_stay;
        $this->dispatch('open-modal', 'finish-match');
    }

    public function finishMatch(): void
    {
        $this->validate([
            'team1_score' => ['required', 'integer', 'min:0'],
            'team2_score' => ['required', 'integer', 'min:0'],
        ]);

        $match = GameMatch::with('matchPlayers.player')->findOrFail($this->finishingMatchId);
        $court = Court::findOrFail($this->finishingCourtId);

        abort_unless($court->game_id === $this->game->id, 403);

        $winningTeam = $this->team1_score > $this->team2_score ? 1 : 2;
        $losingTeam  = $winningTeam === 1 ? 2 : 1;

        DB::transaction(function () use ($match, $court, $winningTeam, $losingTeam) {
            $finishedAt = now();
            $endOfQueue = $this->endOfQueueTimestamp();

            $match->update([
                'status'      => 'finished',
                'team1_score' => $this->team1_score,
                'team2_score' => $this->team2_score,
                'finished_at' => $finishedAt,
            ]);

            $hasNovice  = $match->matchPlayers->contains(fn($mp) => $mp->player->level === 'novice');
            $hasLowInt  = $match->matchPlayers->contains(fn($mp) => $mp->player->level === 'low_intermediate');

            foreach ($match->matchPlayers as $mp) {
                $result = $mp->team === $winningTeam ? 'win' : 'loss';
                $mp->update(['result' => $result]);

                $newStatus = ($mp->team === $winningTeam && $this->winnersStay) ? 'playing' : 'queued';
                $increment = $result === 'win' ? ['wins' => DB::raw('wins + 1')] : ['losses' => DB::raw('losses + 1')];
                $queuedAt  = $newStatus === 'queued' ? ['queued_at' => $endOfQueue] : [];

                $noviceCredit = [];
                if ($hasNovice && $mp->player->level !== 'novice' && $mp->player->play_with_novice && $mp->player->novice_play_count > 0) {
                    $newCount = $mp->player->novice_play_count - 1;
                    $noviceCredit = [
                        'novice_play_count' => $newCount,
                        'play_with_novice'  => $newCount > 0,
                    ];
                }

                $lowCredit = [];
                if ($hasLowInt && $mp->player->level === 'hi_intermediate' && $mp->player->play_with_low_intermediate && $mp->player->low_intermediate_play_count > 0) {
                    $newCount = $mp->player->low_intermediate_play_count - 1;
                    $lowCredit = [
                        'low_intermediate_play_count' => $newCount,
                        'play_with_low_intermediate'  => $newCount > 0,
                    ];
                }

                $mp->player->update(array_merge($increment, ['status' => $newStatus], $queuedAt, $noviceCredit, $lowCredit));
            }

            $court->update(['status' => 'idle', 'idled_at' => $finishedAt]);
        });

        $this->finishingMatchId = null;
        $this->finishingCourtId = null;
        $this->levelShuffleSeeds = [];
        $this->cancelledSets = [];
        $this->dispatch('close-modal', 'finish-match');
        $this->dispatch('clear-queue-seeds');

        // Promote any pending request groups whose players are now all free
        $this->promoteRequestGroups();
    }

    private function promoteRequestGroups(): void
    {
        RequestGroup::with('players')
            ->where('game_id', $this->game->id)
            ->where('status', 'pending')
            ->get()
            ->each(function ($rg) {
                if ($rg->players->every(fn($p) => $p->fresh()->status === 'queued')) {
                    $rg->update(['status' => 'ready']);
                }
            });
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
            $rg = RequestGroup::create([
                'game_id' => $this->game->id,
                'status'  => 'pending',
            ]);
            $rg->players()->attach($players->pluck('id'));

            // Reserve queued players immediately
            $players->where('status', 'queued')->each(
                fn($p) => $p->update(['status' => 'queued']) // keep queued_at, just mark reserved via group
            );

            // Check if already all free
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
    public function toggleBulkPlayer(int $playerId): void
    {
        if (in_array($playerId, $this->selectedPlayerIds)) {
            $this->selectedPlayerIds = array_values(array_filter($this->selectedPlayerIds, fn($id) => $id !== $playerId));
        } else {
            $this->selectedPlayerIds[] = $playerId;
        }
    }

    public function bulkReactivate(): void
    {
        $this->game->players()
            ->whereIn('id', $this->selectedPlayerIds)
            ->where('status', 'inactive')
            ->each(fn($p) => $p->update(['status' => 'queued', 'queued_at' => now()]));
        $this->selectedPlayerIds = [];
    }

    public function bulkDeactivate(): void
    {
        $this->game->players()
            ->whereIn('id', $this->selectedPlayerIds)
            ->where('status', '!=', 'playing')
            ->each(fn($p) => $p->update(['status' => 'inactive']));
        $this->selectedPlayerIds = [];
    }

    public function selectAllPlayers(): void
    {
        $this->selectedPlayerIds = $this->game->players()
            ->when($this->playerListSearch, fn($q) => $q->where('name', 'like', '%' . $this->playerListSearch . '%'))
            ->where('status', '!=', 'playing')
            ->pluck('id')
            ->toArray();
    }

    private function endOfQueueTimestamp(): \Carbon\Carbon
    {
        $latestQueued = $this->game->players()
            ->where('status', 'queued')
            ->max('queued_at');

        $latestRgQueued = RequestGroup::with('players')
            ->where('game_id', $this->game->id)
            ->whereIn('status', ['pending', 'ready'])
            ->get()
            ->flatMap(fn($rg) => $rg->players)
            ->max('queued_at');

        $latest = collect([$latestQueued, $latestRgQueued])->filter()->max();
        return \Carbon\Carbon::parse($latest ?? now())->addSecond();
    }

    public function restartTimer(): void
    {
        $now = now();
        $this->game->update(['started_at' => $now]);
        $this->game->players()
            ->where('status', 'queued')
            ->update(['queued_at' => $now]);
        $this->game->refresh();
    }

    public function resetQueue(): void
    {
        $this->game->players()
            ->where('status', '!=', 'playing')
            ->update(['status' => 'queued', 'queued_at' => now(), 'queued_mode' => null]);
    }

    public function openPlayerList(): void
    {
        $this->playerListSearch  = '';
        $this->selectedPlayerIds = [];
        $this->dispatch('open-modal', 'player-list');
    }

    public function recalculateStats(): void
    {
        $this->game->players()->each(function ($player) {
            $wins   = $player->matchPlayers()->where('result', 'win')->count();
            $losses = $player->matchPlayers()->where('result', 'loss')->count();
            $player->update(['wins' => $wins, 'losses' => $losses]);
        });
    }

    public function removePlayer(int $playerId): void
    {
        $player = $this->game->players()->findOrFail($playerId);
        abort_if($player->isPlaying(), 403);
        $player->delete();
    }

    public function deactivatePlayer(int $playerId): void
    {
        $player = $this->game->players()->findOrFail($playerId);
        abort_if($player->isPlaying(), 403);
        $player->update(['status' => 'inactive']);
    }

    public function reactivatePlayer(int $playerId): void
    {
        $player = $this->game->players()->findOrFail($playerId);
        abort_unless($player->isInactive(), 403);
        $player->update(['status' => 'queued', 'queued_at' => now()]);
    }

    public function changeLevel(int $playerId, string $level): void
    {
        abort_unless(in_array($level, ['novice', 'low_intermediate', 'hi_intermediate']), 403);
        $this->game->players()->findOrFail($playerId)->update(['level' => $level]);
    }

    public function togglePaid(int $playerId): void
    {
        $player = $this->game->players()->findOrFail($playerId);
        $player->update(['paid' => !$player->paid]);
    }
}; ?>

<div wire:poll.3000ms>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $game->name }} — Queue</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                {{ $game->date->format('M d, Y') }} · {{ $game->number_of_courts }} {{ Str::plural('court', $game->number_of_courts) }}
                @if ($game->score_type === 'points')
                    · {{ $game->score_limit }}pts
                @else
                    · {{ $game->game_duration }}min
                @endif
                @if ($game->started_at)
                    ·
                    <span
                        x-data="timer({{ $game->started_at->timestamp }}, true)"
                        x-text="elapsed"
                        class="font-mono text-green-600 dark:text-green-400"
                    ></span>
                    <button wire:click="restartTimer" title="Restart game timer" class="ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition text-xs">↺</button>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if ($game->auto_assign)
                <x-primary-button wire:click="autoAssign">⚡ Auto Assign</x-primary-button>
            @endif
            @if (in_array($game->pairing_mode, ['level', 'win_loss']))
                <button wire:click="openRequestGroup" class="text-sm px-3 py-1.5 rounded-lg border border-purple-300 dark:border-purple-700 text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition font-medium">
                    🎯 Request
                </button>
            @endif
            <button wire:click="openPlayerList" class="text-sm px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500 transition">
                👥 Players
            </button>

            {{-- More dropdown --}}
            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <button x-on:click="open = !open" class="text-sm px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500 transition">
                    ⋯ More
                </button>
                <div x-show="open" x-cloak x-transition
                    class="absolute right-0 mt-1 w-44 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-lg z-50 overflow-hidden">
                    <button wire:click="openStats" x-on:click="open = false" class="w-full text-left px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">📊 Stats</button>
                    <button wire:click="openHistory" x-on:click="open = false" class="w-full text-left px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">📋 History</button>
                    <div class="border-t border-gray-100 dark:border-gray-700"></div>
                    <button wire:click="$dispatch('open-modal', 'confirm-reset-queue')" x-on:click="open = false" class="w-full text-left px-4 py-2.5 text-sm text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">↺ Reset Queue</button>
                </div>
            </div>

            <a href="{{ route('games') }}" wire:navigate
                x-on:click="localStorage.setItem('queueSeeds_{{ $game->id }}', JSON.stringify($wire.levelShuffleSeeds))"
                class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition">
                ← Back
            </a>
        </div>
    </div>

    {{-- Pairing Mode --}}
    <div class="flex items-center gap-2 mb-6">
        <span class="text-xs text-gray-500 dark:text-gray-400 mr-1">Pairing:</span>
        @foreach (['level' => 'By Level', 'win_loss' => 'Win/Loss'] as $value => $label)
            <button wire:click="openPairingSwitch('{{ $value }}')"
                class="text-xs px-3 py-1.5 rounded-full border font-medium transition
                    {{ $game->pairing_mode === $value ? 'border-wimbledon-600 bg-wimbledon-50 dark:bg-wimbledon-900/20 text-wimbledon-700 dark:text-wimbledon-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500' }}">
                {{ $label }}
            </button>
        @endforeach
        @if ($game->pending_pairing_mode)
            <span class="text-xs text-amber-600 dark:text-amber-400 ml-1">
                ↳ switching to {{ $game->pending_pairing_mode === 'level' ? 'By Level' : 'Win/Loss' }} after current queue
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 items-start {{ $queueDetached ? '' : 'lg:grid-cols-3' }}">

        {{-- Courts Panel --}}
        <div class="{{ $queueDetached ? '' : 'lg:col-span-2' }} space-y-4">
            <div class="flex items-center justify-between mb-3 h-8">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Courts</h3>
                <div class="flex items-center gap-2">
                    @if ($queueDetached)
                        <button wire:click="$set('queueDetached', false)" class="text-xs px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500 transition">← Show Queue</button>
                    @endif
                    <a href="{{ route('games.display', $game->id) }}" target="_blank" rel="noopener" wire:navigate.off
                        class="text-xs px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500 transition">🍻 Display</a>
                    <a href="{{ route('game.register', $game->token) }}" target="_blank" rel="noopener" wire:navigate.off
                        class="text-xs px-2.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500 transition">📋 Register</a>
                    <button wire:click="addCourt" class="text-xs px-2.5 py-1 rounded-lg border border-wimbledon-300 dark:border-wimbledon-700 text-wimbledon-600 dark:text-wimbledon-400 hover:bg-wimbledon-50 dark:hover:bg-wimbledon-900/20 transition font-medium">+ Add Court</button>
                </div>
            </div>

            @foreach ($courts as $court)
                @php $match = $court->currentMatch; @endphp
                <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $court->isPlaying() ? 'border-green-400 dark:border-green-600' : ($court->isAssigned() ? 'border-yellow-400 dark:border-yellow-600' : (!$court->active ? 'border-gray-200 dark:border-gray-700 opacity-50' : 'border-gray-200 dark:border-gray-700')) }} p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white">Court {{ $court->court_number }}</span>
                            @if ($court->code)
                                <a href="{{ route('court.view', $court->code) }}" target="_blank" rel="noopener" wire:navigate.off
                                    class="text-xs font-mono px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition" title="Court link">
                                    {{ $court->code }}
                                </a>
                            @endif
                            @if (isset($repeatCourtIds[$court->id]))
                                <span class="text-xs px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400 font-medium">Same group</span>
                            @endif
                            {{-- Active toggle --}}
                            @if ($court->isIdle())
                                <button wire:click="toggleCourtActive({{ $court->id }})"
                                    class="relative inline-flex h-4 w-7 items-center rounded-full transition {{ $court->active ? 'bg-wimbledon-600' : 'bg-gray-300 dark:bg-gray-600' }}"
                                    title="{{ $court->active ? 'Court is on — click to turn off' : 'Court is off — click to turn on' }}">
                                    <span class="inline-block h-3 w-3 transform rounded-full bg-white shadow transition {{ $court->active ? 'translate-x-3.5' : 'translate-x-0.5' }}"></span>
                                </button>
                            @endif
                            <button wire:click="toggleCourtType({{ $court->id }})"
                                class="text-xs px-2 py-0.5 rounded-full border transition
                                    {{ $court->isPlaying() ? 'border-gray-200 dark:border-gray-700 text-gray-400 cursor-not-allowed' : 'border-wimbledon-300 text-wimbledon-600 dark:text-wimbledon-400 hover:bg-wimbledon-50 dark:hover:bg-wimbledon-900/20 cursor-pointer' }}">
                                {{ ucfirst($court->type) }}
                            </button>
                            @if ($court->isIdle() && $court->idled_at)
                                <span
                                    x-data="timer({{ $court->idled_at->timestamp }})"
                                    x-text="elapsed"
                                    class="font-mono text-xs text-gray-400 dark:text-gray-500"
                                ></span>
                            @endif
                            @if ($court->isPlaying() && $match?->started_at)
                                <span
                                    x-data="timer({{ $match->started_at->timestamp }})"
                                    x-text="elapsed"
                                    class="font-mono text-xs text-green-600 dark:text-green-400"
                                ></span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($court->isPlaying())
                                <span class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Playing
                                </span>
                                <button wire:click="openFinishMatch({{ $court->id }})"
                                    class="text-xs px-3 py-1 rounded-lg bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 hover:bg-red-200 transition font-medium">
                                    Finish
                                </button>
                            @elseif ($court->isAssigned())
                                <span class="text-xs text-yellow-600 dark:text-yellow-400">Ready</span>
                                @if (isset($repeatCourtIds[$court->id]))
                                    <button wire:click="reshuffleCourt({{ $court->id }})"
                                        class="text-xs px-3 py-1 rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 hover:bg-purple-200 transition font-medium"
                                        title="Same group has played together before">
                                        🔀 Reshuffle
                                    </button>
                                @endif
                                <button wire:click="openSkipConfirm({{ $court->id }})"
                                    class="text-xs px-3 py-1 rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400 hover:bg-gray-200 transition font-medium">
                                    ↷ Skip
                                </button>
                                <button wire:click="startMatch({{ $court->id }})"
                                    class="text-xs px-3 py-1 rounded-lg bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/30 dark:text-wimbledon-400 hover:bg-wimbledon-200 transition font-medium">
                                    ▶ Start
                                </button>
                            @else
                                @php
                                    $needed = $court->type === 'doubles' ? 4 : 2;
                                    $hasCompleteGroup = $completeGroupSizes >= $needed;
                                @endphp
                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $court->active ? 'Idle' : 'Off' }}</span>
                                @if ($court->active)
                                    <button wire:click="autoAssignCourt({{ $court->id }})"
                                        class="text-xs px-3 py-1 rounded-lg bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/30 dark:text-wimbledon-400 hover:bg-wimbledon-200 transition font-medium">
                                        ⚡ Auto
                                    </button>
                                    @if (!$hasCompleteGroup && $queue->count() >= $needed)
                                        <button wire:click="mixAndFillCourt({{ $court->id }})"
                                            class="text-xs px-3 py-1 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 hover:bg-amber-200 transition font-medium" title="Mix winners and losers to fill this court">
                                            ⇄ Mix & Fill
                                        </button>
                                    @endif
                                    @if (!$game->auto_assign)
                                        <button wire:click="openManualAssign({{ $court->id }}, 1)"
                                            class="text-xs px-3 py-1 rounded-lg bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 hover:bg-gray-200 transition font-medium">
                                            + Assign
                                        </button>
                                    @endif
                                @endif
                            @endif
                        </div>
                    </div>

                    @if ($match)
                        @php
                            $team1       = $match->matchPlayers->where('team', 1);
                            $team2       = $match->matchPlayers->where('team', 2);
                            $perTeam     = $court->type === 'doubles' ? 2 : 1;
                            $team1Missing = max(0, $perTeam - $team1->count());
                            $team2Missing = max(0, $perTeam - $team2->count());
                            $hasMissing   = $team1Missing + $team2Missing > 0;
                        @endphp

                        @if ($hasMissing)
                            <div class="flex items-center gap-1.5 mb-2 px-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                <span class="text-xs text-amber-600 dark:text-amber-400 font-medium">Missing {{ $team1Missing + $team2Missing }} {{ Str::plural('player', $team1Missing + $team2Missing) }} — select a replacement below</span>
                            </div>
                        @endif

                        <div class="grid grid-cols-2 gap-3">
                            @foreach ([1 => [$team1, $team1Missing], 2 => [$team2, $team2Missing]] as $teamNum => [$players, $missing])
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Team {{ $teamNum }}</p>
                                    @foreach ($players as $mp)
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="flex items-center gap-1.5">
                                                @php
                                                    $levelColor = match($mp->player->level) {
                                                        'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                                        'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                                        'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                                    };
                                                @endphp
                                                <button wire:click="openPlayerProfile({{ $mp->player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $mp->player->name }}{{ $mp->player->novice_play_count > 0 ? ' ' . str_repeat('😊', $mp->player->novice_play_count) : '' }}{{ $mp->player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $mp->player->low_intermediate_play_count) : '' }}</button>
                                                <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $mp->player->level_label }}</span>
                                            </div>
                                            @if (!$court->isPlaying())
                                                <button wire:click="openSwitchPlayer({{ $mp->id }})" class="text-xs px-2 py-0.5 rounded-md bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/30 dark:text-wimbledon-400 hover:bg-wimbledon-200 transition font-medium">Switch</button>
                                                <button wire:click="openRestModal({{ $mp->id }})" class="text-xs px-2 py-0.5 rounded-md bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400 hover:bg-orange-200 transition font-medium">Rest</button>
                                            @endif
                                        </div>
                                    @endforeach
                                    @for ($s = 0; $s < $missing; $s++)
                                        <button wire:click="openManualAssign({{ $court->id }}, {{ $teamNum }})"
                                            class="w-full flex items-center gap-1.5 mt-1 px-2 py-1.5 rounded-md border border-dashed border-amber-400 dark:border-amber-600 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition">
                                            <span class="text-sm leading-none">+</span>
                                            <span class="text-xs font-medium">Add Player</span>
                                        </button>
                                    @endfor
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-4">No players assigned</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Queue Panel --}}
        @if (!$queueDetached)
        <div class="lg:sticky lg:top-6 flex flex-col max-h-[calc(100vh-6rem)] overflow-y-auto">
            <div class="flex items-center justify-between mb-3 h-8">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">
                    Queue <span class="text-gray-400">({{ $queue->count() + $requestGroups->where('status', 'ready')->count() }})</span>
                </h3>
                <div class="flex items-center gap-2">
                    <a href="{{ route('games.queue-display', $game->id) }}" target="_blank" rel="noopener" wire:navigate.off
                        wire:click="$set('queueDetached', true)"
                        class="text-xs px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500 transition" title="Open queue in new tab">
                        &#x2197; Pop out
                    </a>
                    <span class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Live
                    </span>
                </div>
            </div>

            {{-- Pending Request Groups (waiting for players to finish) --}}
            @if ($requestGroups->where('status', 'pending')->reject(fn($rg) => $rg->isReady())->isNotEmpty())
                <div class="mb-4 space-y-2">
                    <p class="text-xs font-semibold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-2">Requested</p>
                    @foreach ($requestGroups->where('status', 'pending')->reject(fn($rg) => $rg->isReady()) as $rg)
                        <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $rg->isReady() ? 'border-purple-400 dark:border-purple-600' : 'border-purple-200 dark:border-purple-800' }} overflow-hidden">
                            <div class="px-4 py-2 border-b {{ $rg->isReady() ? 'border-purple-100 dark:border-purple-800' : 'border-purple-50 dark:border-purple-900' }} flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">🎯 Request</span>
                                    @if ($rg->isReady())
                                        <span class="text-xs px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-600 dark:bg-purple-900/40 dark:text-purple-400 font-medium">Ready</span>
                                    @else
                                        <span class="text-xs px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400 font-medium">Waiting</span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($rg->isReady())
                                        <button wire:click="assignRequestGroup({{ $rg->id }})" class="text-xs px-2.5 py-1 rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 hover:bg-purple-200 transition font-medium">
                                            → Assign to Court
                                        </button>
                                    @endif
                                    <button wire:click="cancelRequestGroup({{ $rg->id }})" class="text-xs text-gray-400 hover:text-red-500 transition" title="Cancel request">
                                        ✕
                                    </button>
                                </div>
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
                                            <button wire:click="openPlayerProfile({{ $player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $player->name }}</button>
                                            <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                                        </div>
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

            @if (in_array($game->pairing_mode, ['level', 'win_loss']))
                @php
                    $readyLevels = $upNext->where('ready', true)
                        ->pluck('level_key')
                        ->filter()
                        ->unique()->values();
                    $levelButtons = $game->pairing_mode === 'level' ? [
                        'hi_intermediate'  => ['label' => 'Hi Int',  'color' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 hover:bg-orange-200'],
                        'low_intermediate' => ['label' => 'Low Int', 'color' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 hover:bg-yellow-200'],
                        'novice'           => ['label' => 'Novice',  'color' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 hover:bg-blue-200'],
                    ] : [
                        'winners' => ['label' => 'Winners', 'color' => 'bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/30 dark:text-wimbledon-400 hover:bg-wimbledon-200'],
                        'losers'  => ['label' => 'Losers',  'color' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 hover:bg-red-200'],
                    ];
                @endphp
                @if ($game->pairing_mode === 'level' || $readyLevels->isNotEmpty())
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-1.5">
                            @if ($readyLevels->isNotEmpty())
                                @foreach ($levelButtons as $levelKey => $btn)
                                    @if ($readyLevels->contains($levelKey))
                                        <button wire:click="reshuffleLevel('{{ $levelKey }}')"
                                            class="flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg font-medium transition {{ $btn['color'] }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            {{ $btn['label'] }}
                                        </button>
                                    @endif
                                @endforeach
                            @endif
                        </div>
                        @if ($game->pairing_mode === 'level')
                            <label class="flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" wire:model.live="game.strict_level" wire:change="$dispatch('toggle-strict-level')" class="sr-only peer" />
                                <button wire:click="toggleStrictLevel" type="button"
                                    class="relative inline-flex h-4 w-7 items-center rounded-full transition {{ $game->strict_level ? 'bg-wimbledon-600' : 'bg-gray-300 dark:bg-gray-600' }}">
                                    <span class="inline-block h-3 w-3 transform rounded-full bg-white shadow transition {{ $game->strict_level ? 'translate-x-3.5' : 'translate-x-0.5' }}"></span>
                                </button>
                                <span class="text-xs text-gray-600 dark:text-gray-400">Same level only</span>
                            </label>
                        @endif
                    </div>
                @endif
            @endif

            {{-- Up Next --}}
            @if ($upNext->isNotEmpty())
                <div class="mb-4 space-y-2">
                    <p class="text-xs font-semibold text-wimbledon-600 dark:text-wimbledon-400 uppercase tracking-wider mb-2">Up Next</p>
                    @php $queueNum = 1; @endphp
                    @foreach ($upNext as $set)
                        @php
                            $isRequest = isset($set['request_group_id']);
                            $ids = $set['players']->pluck('id')->toArray();
                            $borderColor = $isRequest
                                ? 'border-purple-400 dark:border-purple-600'
                                : ($set['ready'] ? 'border-wimbledon-300 dark:border-wimbledon-700' : 'border-gray-200 dark:border-gray-700');
                            $headerBorder = $isRequest
                                ? 'border-purple-100 dark:border-purple-800'
                                : ($set['ready'] ? 'border-wimbledon-100 dark:border-wimbledon-800' : 'border-gray-100 dark:border-gray-700');
                        @endphp
                        <div class="bg-white dark:bg-gray-900 rounded-xl border {{ $borderColor }} overflow-hidden">
                            <div class="px-4 py-2 border-b {{ $headerBorder }} flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-gray-400 dark:text-gray-500 w-5">#{{ $queueNum }}</span>
                                    <span class="text-xs font-semibold {{ $set['color'] }}">{{ $set['label'] }}</span>
                                    @if ($isRequest)
                                        <span class="text-xs px-1.5 py-0.5 rounded-full bg-purple-100 text-purple-600 dark:bg-purple-900/40 dark:text-purple-400 font-medium">Ready</span>
                                    @endif
                                    @if ($set['queued_at'])
                                        <span class="text-xs text-gray-400"
                                            x-data="timer({{ \Carbon\Carbon::parse($set['queued_at'])->timestamp }})"
                                            x-text="elapsed"
                                        ></span>
                                    @endif
                                </div>
                                @if ($set['ready'])
                                    <div class="flex items-center gap-2">
                                        @if ($isRequest)
                                            <button wire:click="assignRequestGroup({{ $set['request_group_id'] }})" class="text-xs px-2.5 py-1 rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 hover:bg-purple-200 transition font-medium">
                                                → Assign to Court
                                            </button>
                                        @else
                                            <button wire:click="assignUpNext({{ json_encode($ids) }})" class="text-xs px-2.5 py-1 rounded-lg bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-300 hover:bg-wimbledon-200 transition font-medium">
                                                → Assign to Court
                                            </button>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $set['players']->count() }}/4 waiting</span>
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
                                    <div wire:key="upnext-player-{{ $player->id }}" class="flex items-center justify-between px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-gray-400 w-4">{{ $i + 1 }}</span>
                                            <div>
                                                <button wire:click="openPlayerProfile({{ $player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $player->name }}{{ $player->novice_play_count > 0 ? ' ' . str_repeat('😊', $player->novice_play_count) : '' }}{{ $player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $player->low_intermediate_play_count) : '' }}</button>
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
                                                <div x-data="timer({{ $player->queued_at->timestamp }})"
                                                    x-text="elapsed"
                                                    :class="seconds >= 1800 ? 'font-mono text-red-500 dark:text-red-400 animate-pulse font-semibold' : 'font-mono text-gray-400 dark:text-gray-500'"
                                                ></div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        @php $queueNum++; @endphp
                    @endforeach
                </div>
            @endif

            @if ($groupedQueue)
                <div class="space-y-4">
                    @foreach ($groupedQueue as $group)
                        @if ($group['players']->isNotEmpty())
                            {{-- players are all shown in Up Next --}}
                        @endif
                    @endforeach
                </div>
            @else
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($queue as $i => $player)
                        <div wire:key="queue-player-{{ $player->id }}" class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-400 w-5">{{ $i + 1 }}</span>
                                <div>
                                    <button wire:click="openPlayerProfile({{ $player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $player->name }}{{ $player->novice_play_count > 0 ? ' ' . str_repeat('😊', $player->novice_play_count) : '' }}{{ $player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $player->low_intermediate_play_count) : '' }}</button>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        @php
                                            $levelColor = match($player->level) {
                                                'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                                'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                                'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                            };
                                        @endphp
                                        <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                                        <span class="text-xs text-gray-400 capitalize">{{ $player->gender }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 text-right">
                                <span class="text-green-600 dark:text-green-400">{{ $player->wins }}W</span>
                                <span class="mx-0.5">/</span>
                                <span class="text-red-500 dark:text-red-400">{{ $player->losses }}L</span>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-sm text-gray-400 dark:text-gray-500">
                            No players in queue
                        </div>
                    @endforelse
                </div>
            @endif
        </div>
        @endif
    </div>

    {{-- Request Group Modal --}}
    <x-modal name="request-group" maxWidth="sm">
        <div class="p-6" x-data="{ search: '' }" x-on:open-modal.window="if ($event.detail === 'request-group') search = ''">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">🎯 Create Request Group</h3>
                <button x-on:click="$dispatch('close-modal', 'request-group')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Select exactly 4 players. Players currently playing will be waited on.</p>

            {{-- Selected players display --}}
            @if (count($requestPlayerIds) > 0)
                @php $selectedPlayers = $game->players()->whereIn('id', $requestPlayerIds)->orderByRaw('FIELD(id, ' . implode(',', $requestPlayerIds) . ')')->get(); @endphp
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach ($selectedPlayers as $sp)
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-300 font-medium">
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
                class="w-full justify-center inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest transition {{ count($requestPlayerIds) !== 4 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300' }}">
                Create Request Group
            </button>
        </div>
    </x-modal>

    {{-- Player List Modal --}}
    <x-modal name="player-list" maxWidth="2xl">
        <div class="p-6 max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">👥 Players</h3>
                <div class="flex items-center gap-2">
                    <button wire:click="recalculateStats" class="text-xs px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition font-medium">↻ Recalculate Stats</button>
                    <button x-on:click="$dispatch('close-modal', 'player-list')" class="text-gray-400 hover:text-gray-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-2 mb-4">
                <input wire:model.live="playerListSearch" type="text" placeholder="Search by name…"
                    class="flex-1 text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-wimbledon-500" />
                @php $allSelectableIds = $this->game->players()->when($playerListSearch, fn($q) => $q->where('name', 'like', '%' . $playerListSearch . '%'))->where('status', '!=', 'playing')->pluck('id')->toArray(); @endphp
                <label class="flex items-center gap-1.5 cursor-pointer flex-shrink-0">
                    <input type="checkbox"
                        wire:click="{{ count($selectedPlayerIds) === count($allSelectableIds) && count($allSelectableIds) > 0 ? '$set(\'selectedPlayerIds\', [])' : 'selectAllPlayers' }}"
                        {{ count($selectedPlayerIds) === count($allSelectableIds) && count($allSelectableIds) > 0 ? 'checked' : '' }}
                        class="w-4 h-4 rounded border-gray-300 text-wimbledon-600 focus:ring-wimbledon-500" />
                    <span class="text-xs text-gray-500 dark:text-gray-400">All</span>
                </label>
            </div>

            @php
                $allPlayers = $this->game->players()
                    ->when($playerListSearch, fn($q) => $q->where('name', 'like', '%' . $playerListSearch . '%'))
                    ->orderBy('name')
                    ->get()
                    ->groupBy('status');
                $statusOrder = ['playing', 'queued', 'done', 'inactive'];
            @endphp

            <div class="overflow-y-auto flex-1 space-y-4">
                @foreach ($statusOrder as $statusKey)
                    @if (!empty($allPlayers[$statusKey]) && $allPlayers[$statusKey]->isNotEmpty())
                        @php
                            $statusLabel = match($statusKey) {
                                'playing'  => 'Playing',
                                'queued'   => 'In Queue',
                                'done'     => 'Done',
                                'inactive' => 'Inactive',
                            };
                            $statusColor = match($statusKey) {
                                'playing'  => 'text-green-600 dark:text-green-400',
                                'queued'   => 'text-wimbledon-600 dark:text-wimbledon-400',
                                'done'     => 'text-gray-400 dark:text-gray-500',
                                'inactive' => 'text-red-400 dark:text-red-500',
                            };
                        @endphp
                        <div>
                            <p class="text-xs font-semibold {{ $statusColor }} uppercase tracking-wider mb-2">{{ $statusLabel }} ({{ $allPlayers[$statusKey]->count() }})</p>
                            <div class="divide-y divide-gray-100 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                                @foreach ($allPlayers[$statusKey] as $player)
                                    @php
                                        $levelColor = match($player->level) {
                                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                        };
                                    @endphp
                                    <div x-data="{ editingLevel: false }" class="flex items-center justify-between px-4 py-3 bg-white dark:bg-gray-900 {{ $player->isInactive() ? 'opacity-60' : '' }}">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <input type="checkbox"
                                                wire:click="toggleBulkPlayer({{ $player->id }})"
                                                {{ in_array($player->id, $selectedPlayerIds) ? 'checked' : '' }}
                                                class="w-4 h-4 rounded border-gray-300 text-wimbledon-600 focus:ring-wimbledon-500 flex-shrink-0" />
                                            <span class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $player->name }}</span>
                                            <button x-show="!editingLevel" x-on:click="editingLevel = true"
                                                class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }} flex-shrink-0 hover:opacity-75 transition">{{ $player->level_label }}</button>
                                            <div x-show="editingLevel" x-cloak class="flex items-center gap-1">
                                                @foreach (['novice' => 'Nov', 'low_intermediate' => 'Low', 'hi_intermediate' => 'Hi'] as $val => $short)
                                                    <button wire:click="changeLevel({{ $player->id }}, '{{ $val }}')"
                                                        x-on:click="editingLevel = false"
                                                        class="text-xs px-2 py-0.5 rounded-full transition {{ $player->level === $val ? match($val) { 'novice' => 'bg-blue-500 text-white', 'low_intermediate' => 'bg-yellow-500 text-white', 'hi_intermediate' => 'bg-orange-500 text-white' } : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' }}">{{ $short }}</button>
                                                @endforeach
                                                <button x-on:click="editingLevel = false" class="text-xs text-gray-400 hover:text-gray-600 ml-1">✕</button>
                                            </div>
                                            <span class="text-xs text-gray-400 capitalize flex-shrink-0">{{ $player->gender }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0 ml-2">
                                            <button wire:click="togglePaid({{ $player->id }})" class="text-xs px-2.5 py-1 rounded-lg transition font-medium {{ $player->paid ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400 hover:bg-green-200' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600' }}">{{ $player->paid ? '✓ Paid' : 'Unpaid' }}</button>
                                            @if ($player->isInactive())
                                                <button wire:click="reactivatePlayer({{ $player->id }})" wire:confirm="Re-add {{ $player->name }} to the queue?" class="text-xs px-2.5 py-1 rounded-lg bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-300 hover:bg-wimbledon-200 transition font-medium">Re-add</button>
                                            @elseif (!$player->isPlaying())
                                                <button wire:click="deactivatePlayer({{ $player->id }})" wire:confirm="Mark {{ $player->name }} as inactive? They will be removed from the queue but their stats are kept." class="text-xs px-2.5 py-1 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400 hover:bg-amber-200 transition font-medium">Deactivate</button>
                                            @endif
                                            @if (!$player->isPlaying())
                                                <button wire:click="removePlayer({{ $player->id }})" wire:confirm="Permanently delete {{ $player->name }}? This cannot be undone." class="text-xs px-2.5 py-1 rounded-lg bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400 hover:bg-red-200 transition font-medium">Remove</button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

                @if (collect($allPlayers)->flatten()->isEmpty())
                    <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-8">No players found.</p>
                @endif
            </div>

            {{-- Bulk actions --}}
            @if (count($selectedPlayerIds) > 0)
                <div class="mt-4 flex items-center gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($selectedPlayerIds) }} selected</span>
                    <button wire:click="bulkReactivate" class="text-xs px-3 py-1.5 rounded-lg bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-300 hover:bg-wimbledon-200 transition font-medium">Re-add to Queue</button>
                    <button wire:click="bulkDeactivate" class="text-xs px-3 py-1.5 rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400 hover:bg-amber-200 transition font-medium">Deactivate</button>
                    <button wire:click="$set('selectedPlayerIds', [])" class="text-xs text-gray-400 hover:text-gray-600 transition">Clear</button>
                </div>
            @endif
        </div>
    </x-modal>

    {{-- Reset Queue Modal --}}
    <x-modal name="confirm-reset-queue" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Reset Queue</h3>
                <button x-on:click="$dispatch('close-modal', 'confirm-reset-queue')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">All non-playing players will be re-queued and all wait timers will be reset.</p>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">Players currently on court are not affected.</p>
            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'confirm-reset-queue')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <button wire:click="resetQueue" x-on:click="$dispatch('close-modal', 'confirm-reset-queue')" class="flex-1 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition">Reset Queue</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-action" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Skip Set</h3>
                <button x-on:click="$dispatch('close-modal', 'confirm-action')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">All players will be moved to the end of the queue.</p>
            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'confirm-action')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <x-primary-button wire:click="runSkipCourt" class="flex-1 justify-center">Skip</x-primary-button>
            </div>
        </div>
    </x-modal>

    {{-- Switch Pairing Mode Modal --}}
    <x-modal name="switch-pairing" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Switch Pairing Mode</h3>
                <button x-on:click="$dispatch('close-modal', 'switch-pairing')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">There are players currently in the queue. How would you like to switch?</p>
            <div class="space-y-3 mb-5">
                <button wire:click="updatePairingMode('{{ $pendingSwitchMode }}')"
                    class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-wimbledon-400 dark:hover:border-wimbledon-600 hover:bg-wimbledon-50 dark:hover:bg-wimbledon-900/20 transition">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Switch Now</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Reorganize all queued players into the new mode immediately. Wait times are preserved.</p>
                </button>
                <button wire:click="deferPairingMode('{{ $pendingSwitchMode }}')"
                    class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-amber-400 dark:hover:border-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">After Current Queue</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Current complete groups finish under the old mode. Orphaned players and new registrations go into the new mode immediately.</p>
                </button>
            </div>
            <button x-on:click="$dispatch('close-modal', 'switch-pairing')" class="w-full py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
        </div>
    </x-modal>

    {{-- Game Stats Modal --}}
    <x-modal name="game-stats" maxWidth="lg">
        <div class="p-6 max-h-[80vh] overflow-y-auto" x-data="{ tab: 'overall' }" x-on:open-modal.window="if ($event.detail === 'game-stats') tab = 'overall'">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Game Stats — {{ $game->name }}</h3>
                <button x-on:click="$dispatch('close-modal', 'game-stats')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Tabs --}}
            <div class="flex gap-1 mb-4 border-b border-gray-200 dark:border-gray-700">
                @foreach ([
                    'overall'          => 'Overall',
                    'hi_intermediate'  => 'Hi Int',
                    'low_intermediate' => 'Low Int',
                    'novice'           => 'Novice',
                ] as $key => $label)
                    <button x-on:click="tab = '{{ $key }}'"
                        class="px-3 py-2 text-xs font-medium border-b-2 -mb-px transition"
                        :class="tab === '{{ $key }}' ? 'border-wimbledon-600 text-wimbledon-600 dark:text-wimbledon-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @php
                $allStats = $game->players()
                    ->where(fn($q) => $q->where('wins', '>', 0)->orWhere('losses', '>', 0))
                    ->get()
                    ->map(function ($player) {
                        $points = $player->matchPlayers()
                            ->join('matches', 'match_players.match_id', '=', 'matches.id')
                            ->where('matches.status', 'finished')
                            ->selectRaw('SUM(CASE WHEN match_players.team = 1 THEN matches.team1_score ELSE matches.team2_score END) as total')
                            ->value('total') ?? 0;
                        return [
                            'player' => $player,
                            'points' => (int) $points,
                            'games'  => $player->wins + $player->losses,
                        ];
                    })
                    ->sortByDesc('wins')
                    ->values();

                $statTabs = [
                    'overall'          => $allStats,
                    'hi_intermediate'  => $allStats->filter(fn($r) => $r['player']->level === 'hi_intermediate')->values(),
                    'low_intermediate' => $allStats->filter(fn($r) => $r['player']->level === 'low_intermediate')->values(),
                    'novice'           => $allStats->filter(fn($r) => $r['player']->level === 'novice')->values(),
                ];
            @endphp

            @foreach ($statTabs as $tabKey => $stats)
                <div x-show="tab === '{{ $tabKey }}'" x-cloak>
                    @if ($stats->isEmpty())
                        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-8">No completed matches yet.</p>
                    @else
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        <th class="px-4 py-2.5 text-left w-8">#</th>
                                        <th class="px-4 py-2.5 text-left">Player</th>
                                        <th class="px-4 py-2.5 text-center">W</th>
                                        <th class="px-4 py-2.5 text-center">L</th>
                                        <th class="px-4 py-2.5 text-center">Games</th>
                                        <th class="px-4 py-2.5 text-center">Points</th>
                                        <th class="px-4 py-2.5 text-center">Win %</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($stats as $i => $row)
                                        @php
                                            $p          = $row['player'];
                                            $winPct     = $row['games'] > 0 ? round($p->wins / $row['games'] * 100) : 0;
                                            $levelColor = match($p->level) {
                                                'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                                'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                                                'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                                            };
                                        @endphp
                                        <tr class="bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                                            <td class="px-4 py-3 text-xs font-bold {{ $i === 0 ? 'text-yellow-500' : ($i === 1 ? 'text-gray-400' : ($i === 2 ? 'text-amber-600' : 'text-gray-400 dark:text-gray-500')) }}">
                                                {{ $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i + 1)) }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-2">
                                                    <button wire:click="openPlayerProfile({{ $p->id }})" class="font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $p->name }}{{ $p->novice_play_count > 0 ? ' ' . str_repeat('😊', $p->novice_play_count) : '' }}{{ $p->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $p->low_intermediate_play_count) : '' }}</button>
                                                    @if ($tabKey === 'overall')
                                                        <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $p->level_label }}</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-center font-semibold text-green-600 dark:text-green-400">{{ $p->wins }}</td>
                                            <td class="px-4 py-3 text-center font-semibold text-red-500 dark:text-red-400">{{ $p->losses }}</td>
                                            <td class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">{{ $row['games'] }}</td>
                                            <td class="px-4 py-3 text-center font-semibold text-green-600 dark:text-green-400">{{ $row['points'] }}</td>
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
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-modal>

    {{-- Match History Modal --}}
    <x-modal name="match-history" maxWidth="lg">
        <div class="p-6 max-h-[80vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Match History — {{ $game->name }}</h3>
                <button x-on:click="$dispatch('close-modal', 'match-history')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            @php
                $history = $game->courts()
                    ->with(['matches' => fn($q) => $q->where('status', 'finished')
                        ->with('matchPlayers.player')
                        ->latest('finished_at')])
                    ->get()
                    ->flatMap(fn($court) => $court->matches->map(fn($match) => ['court' => $court, 'match' => $match]))
                    ->sortByDesc(fn($row) => $row['match']->finished_at)
                    ->values();
            @endphp

            @if ($history->isEmpty())
                <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-8">No completed matches yet.</p>
            @else
                <div class="space-y-3">
                    @foreach ($history as $i => $row)
                        @php
                            $match  = $row['match'];
                            $court  = $row['court'];
                            $team1  = $match->matchPlayers->where('team', 1);
                            $team2  = $match->matchPlayers->where('team', 2);
                            $t1win  = $match->team1_score > $match->team2_score;
                            $t2win  = $match->team2_score > $match->team1_score;
                        @endphp
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="flex items-center justify-between px-4 py-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Court {{ $court->court_number }}</span>
                                    @if ($match->pairing_mode)
                                        <span class="text-xs px-1.5 py-0.5 rounded-full {{ $match->pairing_mode === 'level' ? 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400' : 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400' }}">
                                            {{ $match->pairing_mode === 'level' ? 'By Level' : 'Win/Loss' }}
                                        </span>
                                    @endif
                                </div>
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $match->finished_at?->format('g:i a') }}
                                    @if ($match->started_at && $match->finished_at)
                                        · {{ $match->started_at->diffInMinutes($match->finished_at) }}min
                                    @endif
                                </span>
                            </div>
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-4 py-3">
                                <div class="space-y-1">
                                    @foreach ($team1 as $mp)
                                        <p class="text-sm {{ $t1win ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">
                                            <button wire:click="openPlayerProfile({{ $mp->player->id }})" class="hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $mp->player->name }}{{ $mp->player->novice_play_count > 0 ? ' ' . str_repeat('😊', $mp->player->novice_play_count) : '' }}{{ $mp->player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $mp->player->low_intermediate_play_count) : '' }}</button>
                                        </p>
                                    @endforeach
                                </div>
                                <div class="text-center px-2">
                                    <span class="text-lg font-bold {{ $t1win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $match->team1_score }}</span>
                                    <span class="text-gray-300 dark:text-gray-600 mx-1">–</span>
                                    <span class="text-lg font-bold {{ $t2win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $match->team2_score }}</span>
                                </div>
                                <div class="space-y-1 text-right">
                                    @foreach ($team2 as $mp)
                                        <p class="text-sm {{ $t2win ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">
                                            <button wire:click="openPlayerProfile({{ $mp->player->id }})" class="hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $mp->player->name }}{{ $mp->player->novice_play_count > 0 ? ' ' . str_repeat('😊', $mp->player->novice_play_count) : '' }}{{ $mp->player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $mp->player->low_intermediate_play_count) : '' }}</button>
                                        </p>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-modal>

    {{-- Finish Match Modal --}}
    <x-modal name="finish-match" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Finish — {{ $finishCourtLabel }}</h3>
                <button x-on:click="$dispatch('close-modal', 'finish-match')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <x-input-label value="Team 1 Score" />
                    <x-text-input wire:model="team1_score" type="number" min="0" class="text-center text-xl font-bold" />
                </div>
                <div>
                    <x-input-label value="Team 2 Score" />
                    <x-text-input wire:model="team2_score" type="number" min="0" class="text-center text-xl font-bold" />
                </div>
            </div>

            <label class="flex items-center justify-between mb-5">
                <span class="text-sm text-gray-700 dark:text-gray-300">Winners stay on court</span>
                <button type="button" wire:click="$toggle('winnersStay')"
                    class="relative inline-flex h-5 w-9 items-center rounded-full transition {{ $winnersStay ? 'bg-wimbledon-600' : 'bg-gray-300 dark:bg-gray-600' }}">
                    <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition {{ $winnersStay ? 'translate-x-4.5' : 'translate-x-0.5' }}"></span>
                </button>
            </label>

            <x-primary-button wire:click="finishMatch" class="w-full justify-center">Save Result</x-primary-button>
        </div>
    </x-modal>

    {{-- Rest Player Modal --}}
    {{-- Switch Player Modal --}}
    <x-modal name="switch-player" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Switch — {{ $switchPlayerName }}</h3>
                <button x-on:click="$dispatch('close-modal', 'switch-player')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            @if (!empty($switchOtherTeamPlayers))
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Other Team</p>
                <div class="divide-y divide-gray-100 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 mb-4">
                    @foreach ($switchOtherTeamPlayers as $other)
                        <button wire:click="switchPlayer({{ $other['id'] }})" class="w-full flex items-center justify-between px-4 py-3 hover:bg-wimbledon-50 dark:hover:bg-wimbledon-900/20 transition text-left">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $other['name'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $other['level_label'] }} · {{ ucfirst($other['gender']) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">From Queue</p>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-56 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                @forelse ($queue as $player)
                    <button wire:click="switchPlayerFromQueue({{ $player->id }})" class="w-full flex items-center justify-between px-4 py-3 hover:bg-wimbledon-50 dark:hover:bg-wimbledon-900/20 transition text-left">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $player->name }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $player->level_label }} · {{ ucfirst($player->gender) }}</span>
                    </button>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500">No players in queue</p>
                @endforelse
            </div>
        </div>
    </x-modal>

    {{-- Rest Player Modal --}}
    <x-modal name="rest-player" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Rest — {{ $restingPlayerName }}</h3>
                <button x-on:click="$dispatch('close-modal', 'rest-player')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Optionally replace with a queued player, or just send to rest.</p>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 mb-4">
                @forelse ($queue as $player)
                    @php
                        $levelColor = match($player->level) {
                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                        };
                    @endphp
                    <button wire:click="restPlayer({{ $player->id }})" class="w-full flex items-center justify-between px-4 py-3 hover:bg-wimbledon-50 dark:hover:bg-wimbledon-900/20 transition text-left">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $player->name }}{{ $player->novice_play_count > 0 ? ' ' . str_repeat('😊', $player->novice_play_count) : '' }}{{ $player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $player->low_intermediate_play_count) : '' }}</p>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="text-xs px-1.5 py-0.5 rounded-full {{ $levelColor }}">{{ $player->level_label }}</span>
                                <span class="text-xs text-gray-400 capitalize">{{ $player->gender }}</span>
                            </div>
                        </div>
                        <span class="text-xs text-wimbledon-600 dark:text-wimbledon-400 font-medium">Replace →</span>
                    </button>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500">No players in queue</p>
                @endforelse
            </div>
            <button wire:click="restPlayer()" class="w-full py-2 rounded-lg border border-orange-300 text-orange-600 dark:text-orange-400 dark:border-orange-700 text-sm font-medium hover:bg-orange-50 dark:hover:bg-orange-900/20 transition">
                Just Rest (no replacement)
            </button>
        </div>
    </x-modal>

    {{-- Manual Assign Modal --}}
    <x-modal name="manual-assign" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Assign Player</h3>
                <button x-on:click="$dispatch('close-modal', 'manual-assign')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="mb-3 flex gap-2">
                <button wire:click="$set('assignTeam', 1)"
                    class="flex-1 py-1.5 rounded-lg text-sm font-medium border transition {{ $assignTeam === 1 ? 'border-wimbledon-600 bg-wimbledon-50 dark:bg-wimbledon-900/20 text-wimbledon-700 dark:text-wimbledon-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400' }}">
                    Team 1
                </button>
                <button wire:click="$set('assignTeam', 2)"
                    class="flex-1 py-1.5 rounded-lg text-sm font-medium border transition {{ $assignTeam === 2 ? 'border-wimbledon-600 bg-wimbledon-50 dark:bg-wimbledon-900/20 text-wimbledon-700 dark:text-wimbledon-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400' }}">
                    Team 2
                </button>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-72 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                @foreach ($queue as $player)
                    <button wire:click="assignPlayer({{ $player->id }})" class="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition text-left">
                        <button wire:click="openPlayerProfile({{ $player->id }})" class="text-sm font-medium text-gray-900 dark:text-white hover:text-green-600 dark:hover:text-green-400 transition text-left">{{ $player->name }}{{ $player->novice_play_count > 0 ? ' ' . str_repeat('😊', $player->novice_play_count) : '' }}{{ $player->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $player->low_intermediate_play_count) : '' }}</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $player->level_label }} · {{ ucfirst($player->gender) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </x-modal>

    {{-- Player Profile Modal --}}
    <x-modal name="player-profile" maxWidth="md">
        <div class="p-6 max-h-[80vh] overflow-y-auto">
            @if ($viewingPlayerId)
                @php
                    $vp = $game->players()->with('matchPlayers.gameMatch')->find($viewingPlayerId);
                @endphp
                @if ($vp)
                    @php
                        $vpLevelColor = match($vp->level) {
                            'novice'           => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                            'low_intermediate' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                            'hi_intermediate'  => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                        };
                        $vpGames  = $vp->wins + $vp->losses;
                        $vpWinPct = $vpGames > 0 ? round($vp->wins / $vpGames * 100) : 0;
                        $vpPoints = $vp->matchPlayers()
                            ->join('matches', 'match_players.match_id', '=', 'matches.id')
                            ->where('matches.status', 'finished')
                            ->selectRaw('SUM(CASE WHEN match_players.team = 1 THEN matches.team1_score ELSE matches.team2_score END) as total')
                            ->value('total') ?? 0;
                    @endphp

                    {{-- Header --}}
                    <div class="flex items-start justify-between mb-5">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ $vp->name }}{{ $vp->novice_play_count > 0 ? ' ' . str_repeat('😊', $vp->novice_play_count) : '' }}{{ $vp->low_intermediate_play_count > 0 ? ' ' . str_repeat('🤝', $vp->low_intermediate_play_count) : '' }}
                            </h3>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs px-1.5 py-0.5 rounded-full {{ $vpLevelColor }}">{{ $vp->level_label }}</span>
                                <span class="text-xs text-gray-400 capitalize">{{ $vp->gender }}</span>
                                <span class="text-xs text-gray-400 capitalize">{{ $vp->status }}</span>
                            </div>
                        </div>
                        <button x-on:click="$dispatch('close-modal', 'player-profile')" class="text-gray-400 hover:text-gray-600 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {{-- Stats row --}}
                    <div class="grid grid-cols-4 gap-3 mb-5">
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ $vp->wins }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Wins</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-red-500 dark:text-red-400">{{ $vp->losses }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Losses</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ (int) $vpPoints }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Points</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-gray-700 dark:text-gray-300">{{ $vpWinPct }}%</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Win %</p>
                        </div>
                    </div>

                    {{-- Match history --}}
                    @php
                        $vpHistory = $vp->matchPlayers()
                            ->with(['gameMatch.matchPlayers.player', 'gameMatch.court'])
                            ->whereHas('gameMatch', fn($q) => $q->where('status', 'finished'))
                            ->latest()
                            ->get();
                    @endphp
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Match History</p>
                    @if ($vpHistory->isEmpty())
                        <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-4">No matches played yet.</p>
                    @else
                        <div class="space-y-2">
                            @foreach ($vpHistory as $vmp)
                                @php
                                    $vm      = $vmp->gameMatch;
                                    $vt1     = $vm->matchPlayers->where('team', 1);
                                    $vt2     = $vm->matchPlayers->where('team', 2);
                                    $vt1win  = $vm->team1_score > $vm->team2_score;
                                    $vt2win  = $vm->team2_score > $vm->team1_score;
                                    $myTeam  = $vmp->team;
                                    $won     = $vmp->result === 'win';
                                @endphp
                                <div class="rounded-lg border {{ $won ? 'border-green-200 dark:border-green-800' : 'border-red-200 dark:border-red-800' }} overflow-hidden">
                                    <div class="flex items-center justify-between px-3 py-1.5 {{ $won ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20' }} border-b {{ $won ? 'border-wimbledon-100 dark:border-wimbledon-800' : 'border-red-100 dark:border-red-800' }}">
                                        <span class="text-xs font-semibold {{ $won ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400' }}">{{ $won ? 'Win' : 'Loss' }}</span>
                                        <div class="flex items-center gap-2">
                                            @if ($vm->court)
                                                <span class="text-xs text-gray-400">Court {{ $vm->court->court_number }}</span>
                                            @endif
                                            @if ($vm->pairing_mode)
                                                <span class="text-xs text-gray-400">· {{ $vm->pairing_mode === 'level' ? 'By Level' : 'Win/Loss' }}</span>
                                            @endif
                                            <span class="text-xs text-gray-400">{{ $vm->finished_at?->format('g:i a') }}</span>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-3 py-2">
                                        <div class="space-y-0.5">
                                            @foreach ($vt1 as $vmp2)
                                                <p class="text-xs {{ $vmp2->player_id === $vp->id ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">{{ $vmp2->player->name }}</p>
                                            @endforeach
                                        </div>
                                        <div class="text-center px-2">
                                            <span class="text-sm font-bold {{ $vt1win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $vm->team1_score }}</span>
                                            <span class="text-gray-300 dark:text-gray-600 mx-0.5">–</span>
                                            <span class="text-sm font-bold {{ $vt2win ? 'text-green-600 dark:text-green-400' : 'text-gray-400' }}">{{ $vm->team2_score }}</span>
                                        </div>
                                        <div class="space-y-0.5 text-right">
                                            @foreach ($vt2 as $vmp2)
                                                <p class="text-xs {{ $vmp2->player_id === $vp->id ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">{{ $vmp2->player->name }}</p>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            @endif
        </div>
    </x-modal>
</div>

@script
<script>
    (function () {
        const key = 'queueSeeds_{{ $game->id }}';
        const saved = localStorage.getItem(key);
        if (saved) {
            try {
                const seeds = JSON.parse(saved);
                if (seeds && typeof seeds === 'object') {
                    $wire.set('levelShuffleSeeds', seeds);
                }
            } catch (e) {}
        }

        $wire.on('clear-queue-seeds', () => {
            localStorage.removeItem(key);
        });
    })();

    Alpine.data('timer', (start, hms = false) => ({
        elapsed: '',
        seconds: 0,
        _interval: null,
        init() {
            this.tick();
            this._interval = setInterval(() => this.tick(), 1000);
        },
        destroy() {
            clearInterval(this._interval);
        },
        tick() {
            const s = Math.max(0, Math.floor(Date.now() / 1000) - start);
            this.seconds = s;
            if (hms) {
                const h = Math.floor(s / 3600);
                const m = Math.floor((s % 3600) / 60);
                const sec = s % 60;
                this.elapsed = (h ? String(h).padStart(2, '0') + ':' : '')
                    + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
            } else {
                this.elapsed = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
            }
        },
    }));
</script>
@endscript
