<?php

use App\Models\Game;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app', ['header' => 'Games'])] class extends Component
{
    public string $name = '';
    public string $date = '';
    public int $number_of_courts = 1;
    public string $pairing_mode = 'random';
    public string $score_type = 'points';
    public ?int $score_limit = 21;
    public ?int $game_duration = null;
    public bool $winners_stay = false;
    public bool $auto_assign = true;
    public int $queue_wait_threshold = 5;

    public ?int $editingId = null;
    public string $qrSvg = '';
    public string $qrGameName = '';
    public string $qrUrl = '';
    public ?int $endingGameId = null;
    public string $endingGameName = '';
    public ?int $endingWinnerId = null;
    public array $endingRankedPlayers = [];
    public ?int $deletingGameId = null;
    public string $deletingGameName = '';

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'date'             => ['required', 'date'],
            'number_of_courts' => ['required', 'integer', 'min:1'],
            'pairing_mode'     => ['required', 'in:random,level,gender_balanced,win_loss'],
            'score_type'       => ['required', 'in:points,timed'],
            'score_limit'      => ['nullable', 'integer', 'min:1'],
            'game_duration'    => ['nullable', 'integer', 'min:1'],
            'winners_stay'          => ['boolean'],
            'auto_assign'           => ['boolean'],
            'queue_wait_threshold'  => ['required', 'integer', 'min:1'],
        ];
    }

    public function with(): array
    {
        return [
            'games' => Auth::user()->games()->with('winner')->withCount(['courts as matches_count' => fn($q) => $q->join('matches', 'courts.id', '=', 'matches.court_id')->where('matches.status', 'finished')])->latest('date')->get(),
        ];
    }

    public function openCreate(): void
    {
        $this->reset('name', 'date', 'editingId');
        $this->number_of_courts     = 1;
        $this->pairing_mode         = 'random';
        $this->score_type           = 'points';
        $this->score_limit          = 21;
        $this->game_duration        = null;
        $this->winners_stay         = false;
        $this->auto_assign          = true;
        $this->queue_wait_threshold = 5;
        $this->dispatch('open-modal', 'game-form');
    }

    public function openEdit(Game $game): void
    {
        $this->editingId            = $game->id;
        $this->name                 = $game->name;
        $this->date                 = $game->date->format('Y-m-d');
        $this->number_of_courts     = $game->number_of_courts;
        $this->pairing_mode         = $game->pairing_mode;
        $this->score_type           = $game->score_type;
        $this->score_limit          = $game->score_limit;
        $this->game_duration        = $game->game_duration;
        $this->winners_stay         = $game->winners_stay;
        $this->auto_assign          = $game->auto_assign;
        $this->queue_wait_threshold = $game->queue_wait_threshold;
        $this->dispatch('open-modal', 'game-form');
    }

    public function save(): void
    {

        $data = [
            'name'             => $this->name,
            'date'             => $this->date,
            'number_of_courts' => $this->number_of_courts,
            'pairing_mode'     => $this->pairing_mode,
            'score_type'       => $this->score_type,
            'score_limit'      => $this->score_type === 'points' ? $this->score_limit : null,
            'game_duration'    => $this->score_type === 'timed' ? $this->game_duration : null,
            'winners_stay'         => $this->winners_stay,
            'auto_assign'          => $this->auto_assign,
            'queue_wait_threshold' => $this->queue_wait_threshold,
        ];

        if ($this->editingId) {
            Auth::user()->games()->findOrFail($this->editingId)->update($data);
        } else {
            Auth::user()->games()->create($data);
        }

        $this->dispatch('close-modal', 'game-form');
    }

    public function openDelete(Game $game): void
    {
        Auth::user()->games()->findOrFail($game->id);
        $this->deletingGameId   = $game->id;
        $this->deletingGameName = $game->name;
        $this->dispatch('open-modal', 'confirm-delete');
    }

    public function confirmDelete(): void
    {
        Auth::user()->games()->findOrFail($this->deletingGameId)->delete();
        $this->deletingGameId   = null;
        $this->deletingGameName = '';
        $this->dispatch('close-modal', 'confirm-delete');
    }

    public function cancel(): void
    {
        $this->dispatch('close-modal', 'game-form');
    }

    public function startGame(Game $game): void
    {
        Auth::user()->games()->findOrFail($game->id);
        $game->start();
        $this->openQr($game->fresh());
    }

    public function restartGame(Game $game): void
    {
        Auth::user()->games()->findOrFail($game->id);
        $game->restart();
        $this->openQr($game->fresh());
    }

    public function endGame(Game $game): void
    {
        Auth::user()->games()->findOrFail($game->id);
        $this->endingGameId   = $game->id;
        $this->endingGameName = $game->name;

        // Build ranked player list (wins desc → win_pct desc → points desc)
        $this->endingRankedPlayers = $game->players()
            ->where(fn($q) => $q->where('wins', '>', 0)->orWhere('losses', '>', 0))
            ->get()
            ->map(function ($player) {
                $games  = $player->wins + $player->losses;
                $winPct = $games > 0 ? $player->wins / $games : 0;
                return ['id' => $player->id, 'name' => $player->name, 'wins' => $player->wins, 'losses' => $player->losses, 'win_pct' => $winPct];
            })
            ->pipe(fn($col) => $col->sort(fn($a, $b) =>
                [$b['wins'], $b['win_pct']] <=> [$a['wins'], $a['win_pct']]
            )->values())
            ->toArray();

        $this->endingWinnerId = $this->endingRankedPlayers[0]['id'] ?? null;
        $this->dispatch('open-modal', 'confirm-end');
    }

    public function confirmEndGame(): void
    {
        $game = Auth::user()->games()->findOrFail($this->endingGameId);
        $game->end();
        if ($this->endingWinnerId) {
            $game->update(['winner_player_id' => $this->endingWinnerId]);
        }
        $this->endingGameId          = null;
        $this->endingGameName        = '';
        $this->endingWinnerId        = null;
        $this->endingRankedPlayers   = [];
        $this->dispatch('close-modal', 'confirm-end');
    }

    public function duplicateGame(Game $game): void
    {
        Auth::user()->games()->findOrFail($game->id);

        $source = $game->load('players', 'courts.matches.matchPlayers');

        $newGame = Auth::user()->games()->create([
            'name'                 => $source->name . ' (Copy)',
            'date'                 => today()->format('Y-m-d'),
            'number_of_courts'     => $source->number_of_courts,
            'pairing_mode'         => $source->pairing_mode,
            'score_type'           => $source->score_type,
            'score_limit'          => $source->score_limit,
            'game_duration'        => $source->game_duration,
            'winners_stay'         => $source->winners_stay,
            'auto_assign'          => $source->auto_assign,
            'queue_wait_threshold' => $source->queue_wait_threshold,
            'status'               => $source->status,
        ]);

        // Copy players, track old → new ID map
        $playerMap = [];
        foreach ($source->players as $player) {
            $newPlayer = $newGame->players()->create([
                'name'                        => $player->name,
                'level'                       => $player->level,
                'gender'                      => $player->gender,
                'play_with_novice'            => $player->play_with_novice,
                'novice_play_count'           => $player->novice_play_count,
                'play_with_low_intermediate'  => $player->play_with_low_intermediate,
                'low_intermediate_play_count' => $player->low_intermediate_play_count,
                'status'                      => $player->status,
                'paid'                        => $player->paid,
                'wins'                        => $player->wins,
                'losses'                      => $player->losses,
                'queued_at'                   => $player->queued_at,
                'queued_mode'                 => $player->queued_mode,
            ]);
            $playerMap[$player->id] = $newPlayer->id;
        }

        // Copy courts → matches → match_players
        foreach ($source->courts as $court) {
            $newCourt = $newGame->courts()->create([
                'court_number' => $court->court_number,
                'type'         => $court->type,
                'status'       => $court->status,
                'idled_at'     => $court->idled_at,
            ]);

            foreach ($court->matches as $match) {
                $newMatch = $newCourt->matches()->create([
                    'status'            => $match->status,
                    'team1_score'       => $match->team1_score,
                    'team2_score'       => $match->team2_score,
                    'started_at'        => $match->started_at,
                    'finished_at'       => $match->finished_at,
                    'group_fingerprint' => $match->group_fingerprint,
                    'pairing_mode'      => $match->pairing_mode,
                ]);

                foreach ($match->matchPlayers as $mp) {
                    $newMatch->matchPlayers()->create([
                        'player_id' => $playerMap[$mp->player_id] ?? $mp->player_id,
                        'team'      => $mp->team,
                        'result'    => $mp->result,
                    ]);
                }
            }
        }
    }

    public function openQr(Game $game): void
    {
        $this->qrUrl      = route('game.register', $game->token);
        $this->qrGameName = $game->name;
        $this->qrSvg      = (new \SimpleSoftwareIO\QrCode\Generator)->size(180)->generate($this->qrUrl);
        $this->dispatch('open-modal', 'qr-code');
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Games</h2>
        <x-primary-button wire:click="openCreate">+ New Game</x-primary-button>
    </div>

    {{-- Create / Edit Form --}}
    <x-modal name="game-form" maxWidth="2xl">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $editingId ? 'Edit Game' : 'New Game' }}</h3>
                <button x-on:click="$dispatch('close-modal', 'game-form')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form wire:submit="save" class="space-y-4">
                    <div>
                        <x-input-label for="name" value="Game Name" />
                        <x-text-input wire:model="name" id="name" type="text" name="name" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="date" value="Date" />
                        <x-text-input wire:model="date" id="date" type="date" name="date" required />
                        <x-input-error :messages="$errors->get('date')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="number_of_courts" value="Number of Courts" />
                        <x-text-input wire:model="number_of_courts" id="number_of_courts" type="number" name="number_of_courts" min="1" required />
                        <x-input-error :messages="$errors->get('number_of_courts')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="score_limit" value="Points to Win" />
                        <x-text-input wire:model="score_limit" id="score_limit" type="number" name="score_limit" min="1" />
                        <x-input-error :messages="$errors->get('score_limit')" class="mt-1" />
                    </div>

                    <div class="flex gap-3 pt-2">
                        <x-primary-button>{{ $editingId ? 'Update' : 'Create' }}</x-primary-button>
                        <x-secondary-button wire:click="cancel" type="button">Cancel</x-secondary-button>
                    </div>
                </form>
        </div>
    </x-modal>

    {{-- Games Table --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        @if ($games->isEmpty())
            <div class="p-12 text-center text-sm text-gray-500 dark:text-gray-400">
                No games yet. Click <strong>+ New Game</strong> to get started.
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Courts</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Players</th>
                        <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Matches</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($games as $game)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                            <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">{{ $game->name }}</td>
                            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $game->date->format('M d, Y') }}</td>
                            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $game->number_of_courts }}</td>
                            <td class="px-6 py-4">
                                @if ($game->isPending())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Pending</span>
                                @elseif ($game->isOngoing())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-400">Ongoing</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400">Ended</span>
                                    @if ($game->winner_player_id && $game->winner)
                                        <span class="inline-flex items-center gap-1 text-xs text-yellow-600 dark:text-yellow-400">🏆 {{ $game->winner->name }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $game->players()->count() }}</td>
                            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ $game->matches_count }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('games.view', $game->id) }}" wire:navigate class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition">View</a>
                                    @if ($game->isPending())
                                        <button wire:click="startGame({{ $game->id }})" class="text-sm text-wimbledon-600 dark:text-wimbledon-400 hover:text-wimbledon-800 font-medium transition">Start</button>
                                        <button wire:click="openEdit({{ $game->id }})" class="text-sm text-wimbledon-600 dark:text-wimbledon-400 hover:text-wimbledon-800 transition">Edit</button>
                                        <button wire:click="duplicateGame({{ $game->id }})" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 transition">Duplicate</button>
                                        <button wire:click="openDelete({{ $game->id }})" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 transition">Delete</button>
                                    @elseif ($game->isOngoing())
                                        <a href="{{ route('games.queue', $game->id) }}" wire:navigate class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 transition">Queue</a>
                                        <button wire:click="openQr({{ $game->id }})" class="text-sm text-wimbledon-600 dark:text-wimbledon-400 hover:text-wimbledon-800 transition">Show QR</button>
                                        <button wire:click="duplicateGame({{ $game->id }})" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 transition">Duplicate</button>
                                        <button wire:click="endGame({{ $game->id }})" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 transition">End</button>
                                    @else
                                        <button wire:click="restartGame({{ $game->id }})" wire:confirm="Restart this game? Courts and match history will be reset." class="text-sm text-wimbledon-600 dark:text-wimbledon-400 hover:text-wimbledon-800 font-medium transition">Restart</button>
                                        <button wire:click="duplicateGame({{ $game->id }})" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 transition">Duplicate</button>
                                        <button wire:click="openDelete({{ $game->id }})" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 transition">Delete</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Confirm Delete Modal --}}
    <x-modal name="confirm-delete" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete Game</h3>
                <button x-on:click="$dispatch('close-modal', 'confirm-delete')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Are you sure you want to delete <strong class="text-gray-900 dark:text-white">{{ $deletingGameName }}</strong>?</p>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">All players, courts, and match history will be permanently removed.</p>
            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'confirm-delete')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <button wire:click="confirmDelete" class="flex-1 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition">Delete</button>
            </div>
        </div>
    </x-modal>

    {{-- Confirm End Modal --}}
    <x-modal name="confirm-end" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">End Game</h3>
                <button x-on:click="$dispatch('close-modal', 'confirm-end')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Ending <strong class="text-gray-900 dark:text-white">{{ $endingGameName }}</strong>. Players will no longer be able to register.</p>

            @if (!empty($endingRankedPlayers))
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">🏆 Tag Winner <span class="normal-case font-normal text-gray-400">(optional)</span></p>
                <div class="divide-y divide-gray-100 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 mb-5 max-h-56 overflow-y-auto">
                    @foreach ($endingRankedPlayers as $i => $rp)
                        <button wire:click="$set('endingWinnerId', {{ $endingWinnerId === $rp['id'] ? 'null' : $rp['id'] }})" class="w-full flex items-center justify-between px-4 py-2.5 transition text-left {{ $endingWinnerId === $rp['id'] ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                            <div class="flex items-center gap-2">
                                <span class="text-sm">{{ $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ($i + 1) . '.')) }}</span>
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $rp['name'] }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs text-gray-400">{{ $rp['wins'] }}W / {{ $rp['losses'] }}L</span>
                                @if ($endingWinnerId === $rp['id'])
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400 font-medium">Winner</span>
                                @endif
                            </div>
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'confirm-end')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <button wire:click="confirmEndGame" class="flex-1 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition">End Game</button>
            </div>
        </div>
    </x-modal>

    {{-- QR Code Modal --}}
    <x-modal name="qr-code" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Player Registration</h3>
                <button x-on:click="$dispatch('close-modal', 'qr-code')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Share this QR code with players to register for <strong class="text-gray-900 dark:text-white">{{ $qrGameName }}</strong>.
            </p>
            <div class="flex justify-center bg-white p-4 rounded-lg border border-gray-200 mb-4">
                {!! $qrSvg !!}
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Or share this link:</p>
            <a href="{{ $qrUrl }}" target="_blank" class="text-xs text-wimbledon-600 dark:text-wimbledon-400 hover:underline break-all">
                {{ $qrUrl }}
            </a>
        </div>
    </x-modal>
</div>
