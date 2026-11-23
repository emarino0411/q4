<?php

use App\Models\Game;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public Game $game;
    public string $token = '';

    public string $name = '';
    public string $level = '';
    public string $gender = '';
    public bool $play_with_novice = false;
    public int $novice_play_count = 0;
    public bool $play_with_low_intermediate = false;
    public int $low_intermediate_play_count = 0;

    public bool $registered = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $game = Game::where('token', $token)->whereIn('status', ['ongoing', 'ended'])->first();

        if (! $game) {
            abort(404);
        }

        $this->game = $game;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'level'            => ['required', 'in:novice,low_intermediate,hi_intermediate'],
            'gender'           => ['required', 'in:male,female'],
            'play_with_novice'           => ['boolean'],
            'novice_play_count'           => ['nullable', 'integer', 'min:0'],
            'play_with_low_intermediate'         => ['boolean'],
            'low_intermediate_play_count'         => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function register(): void
    {
        $this->validate();

        $this->game->players()->create([
            'name'              => $this->name,
            'level'             => $this->level,
            'gender'            => $this->gender,
            'play_with_novice'           => $this->play_with_novice,
            'novice_play_count'           => $this->play_with_novice ? max(1, $this->novice_play_count) : 0,
            'play_with_low_intermediate'         => $this->level === 'hi_intermediate' ? $this->play_with_low_intermediate : false,
            'low_intermediate_play_count'         => ($this->level === 'hi_intermediate' && $this->play_with_low_intermediate) ? max(1, $this->low_intermediate_play_count) : 0,
            'status'            => 'queued',
            'queued_at'         => now(),
            'queued_mode'       => $this->game->pending_pairing_mode,
        ]);

        $this->registered = true;
    }
}; ?>

<div>
    @if ($game->isEnded())
        <div class="text-center py-6">
            <div class="text-5xl mb-4">🍻</div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">That's a wrap!</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ $game->name }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-500 mb-8">{{ $game->date->format('F d, Y') }}</p>

            @php
                $endedStats = $game->players()
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

                $tiers = [
                    'overall'          => ['label' => 'Overall',          'emoji' => '🏆', $endedStats->values()],
                    'hi_intermediate'  => ['label' => 'Hi Intermediate',  'emoji' => '🟠', $endedStats->filter(fn($r) => $r['player']->level === 'hi_intermediate')->values()],
                    'low_intermediate' => ['label' => 'Low Intermediate', 'emoji' => '🟡', $endedStats->filter(fn($r) => $r['player']->level === 'low_intermediate')->values()],
                    'novice'           => ['label' => 'Novice',           'emoji' => '🔵', $endedStats->filter(fn($r) => $r['player']->level === 'novice')->values()],
                ];
            @endphp

            @if ($endedStats->isEmpty())
                <p class="text-sm text-gray-400 dark:text-gray-500">No match results recorded.</p>
            @else
                <div class="space-y-6 text-left">
                    @foreach ([['Overall', '🏆', $endedStats->values()], ['Hi Intermediate', '🟠', $endedStats->filter(fn($r) => $r['player']->level === 'hi_intermediate')->values()], ['Low Intermediate', '🟡', $endedStats->filter(fn($r) => $r['player']->level === 'low_intermediate')->values()], ['Novice', '🔵', $endedStats->filter(fn($r) => $r['player']->level === 'novice')->values()]] as [$label, $emoji, $rows])
                        @if ($rows->isNotEmpty())
                            <div>
                                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-2">{{ $emoji }} {{ $label }}</p>
                                <div class="space-y-2">
                                    @foreach ($rows->take(3) as $i => $row)
                                        @php
                                            $p = $row['player'];
                                            $medal = ['🥇', '🥈', '🥉'][$i] ?? ($i + 1);
                                            $games = $row['games'];
                                            $winPct = $games > 0 ? round($p->wins / $games * 100) : 0;
                                        @endphp
                                        <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-800 rounded-xl px-4 py-3 {{ $i === 0 ? 'ring-1 ring-yellow-300 dark:ring-yellow-700' : '' }}">
                                            <span class="text-xl w-7 text-center">{{ $medal }}</span>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate">{{ $p->name }}</p>
                                                <p class="text-xs text-gray-400 mt-0.5">{{ $p->wins }}W · {{ $p->losses }}L · {{ $row['games'] }} games · {{ $row['points'] }}pts</p>
                                            </div>

                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @elseif ($registered)
        <div class="text-center py-4">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">You're registered!</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">You've been added to <strong>{{ $game->name }}</strong>. Good luck!</p>
        </div>
    @else
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Join {{ $game->name }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Fill in your details to register as a player.</p>

        <form wire:submit="register" class="space-y-4">
            <div>
                <x-input-label for="name" value="Your Name" />
                <x-text-input wire:model="name" id="name" type="text" name="name" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="gender" value="Gender" />
                <select wire:model="gender" id="gender" name="gender" required
                    class="w-full px-3 py-2 text-sm text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-wimbledon-500 focus:border-green-500">
                    <option value="">Select gender...</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
                <x-input-error :messages="$errors->get('gender')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="level" value="Your Level" />
                <select wire:model.live="level" id="level" name="level" required
                    class="w-full px-3 py-2 text-sm text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-wimbledon-500 focus:border-green-500">
                    <option value="">Select level...</option>
                    <option value="novice">Novice</option>
                    <option value="low_intermediate">Low Intermediate</option>
                    <option value="hi_intermediate">Hi Intermediate</option>
                </select>
                <x-input-error :messages="$errors->get('level')" class="mt-1" />
            </div>

            @if ($level && $level !== 'novice')
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input wire:model.live="play_with_novice" type="checkbox" id="play_with_novice"
                               class="mt-0.5 w-4 h-4 rounded border-gray-300 text-green-600 focus:ring-wimbledon-500">
                        <div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">Willing to play with novice players</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Check this if you're open to being paired with novice players.</p>
                        </div>
                    </label>

                    @if ($play_with_novice)
                        <div>
                            <x-input-label for="novice_play_count" value="How many times are you willing to play with a novice?" />
                            <x-text-input wire:model="novice_play_count" id="novice_play_count" type="number" name="novice_play_count" min="1" max="10" />
                            <x-input-error :messages="$errors->get('novice_play_count')" class="mt-1" />
                        </div>
                    @endif

                    @if ($level === 'hi_intermediate')
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input wire:model.live="play_with_low_intermediate" type="checkbox" id="play_with_low_intermediate"
                                   class="mt-0.5 w-4 h-4 rounded border-gray-300 text-green-600 focus:ring-wimbledon-500">
                            <div>
                                <span class="text-sm font-medium text-gray-900 dark:text-white">Willing to play with low intermediate players</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Check this if you're open to being paired with low intermediate players.</p>
                            </div>
                        </label>
                        @if ($play_with_low_intermediate)
                            <div>
                                <x-input-label for="low_intermediate_play_count" value="How many times are you willing to play with low intermediate?" />
                                <x-text-input wire:model="low_intermediate_play_count" id="low_intermediate_play_count" type="number" name="low_intermediate_play_count" min="1" max="10" />
                                <x-input-error :messages="$errors->get('low_intermediate_play_count')" class="mt-1" />
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            <x-primary-button class="w-full">Register</x-primary-button>
        </form>
    @endif
</div>
