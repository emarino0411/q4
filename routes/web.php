<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Livewire\Volt\Volt::route('/game/register/{token}', 'game-register')->name('game.register');

Livewire\Volt\Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->post('/theme', function (\Illuminate\Http\Request $request) {
    $request->user()->update(['theme' => $request->input('theme') === 'dark' ? 'dark' : 'light']);
    return response()->noContent();
})->name('theme.save');

Route::middleware(['auth', 'verified'])->group(function () {
    Livewire\Volt\Volt::route('/games', 'games')->name('games');
    Livewire\Volt\Volt::route('/games/{game}/queue', 'games.queue')->name('games.queue');
    Livewire\Volt\Volt::route('/games/{game}/view', 'games.view')->name('games.view');
    Livewire\Volt\Volt::route('/games/{game}/players/{player}', 'games.player-history')->name('games.player-history');
});

Livewire\Volt\Volt::route('/games/{game}/display', 'games.display')->name('games.display');

require __DIR__.'/auth.php';
