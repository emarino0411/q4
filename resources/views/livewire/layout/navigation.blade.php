<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 transition-colors duration-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">

            <div class="flex items-center gap-8">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                    <x-application-logo class="w-7 h-7 fill-current text-wimbledon-600 dark:text-wimbledon-400" />
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ config('app.name') }}</span>
                </a>
                <div class="hidden sm:flex items-center gap-6">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        Dashboard
                    </x-nav-link>
                    <x-nav-link :href="route('games')" :active="request()->routeIs('games')" wire:navigate>
                        Games
                    </x-nav-link>
                    @if (auth()->user()->isSuperAdmin())
                        <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')" wire:navigate>
                            Admin
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex items-center gap-3">
                {{-- Dark mode toggle --}}
                <button @click="$store.theme.toggle()"
                        class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    <svg x-show="!$store.theme.dark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                    </svg>
                    <svg x-show="$store.theme.dark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </button>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white focus:outline-none transition">
                            <span x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                                  x-text="name"
                                  x-on:profile-updated.window="name = $event.detail.name"></span>
                            <svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>Profile</x-dropdown-link>
                        <button wire:click="logout" class="w-full text-left">
                            <x-dropdown-link>Log Out</x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="flex items-center gap-2 sm:hidden">
                <button @click="$store.theme.toggle()"
                        class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    <svg x-show="!$store.theme.dark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                    </svg>
                    <svg x-show="$store.theme.dark" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </button>
                <button @click="open = !open" class="p-2 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none transition">
                    <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': !open}" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': !open, 'inline-flex': open}" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': !open}" class="hidden sm:hidden border-t border-gray-200 dark:border-gray-700">
        <div class="py-2">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                Dashboard
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('games')" :active="request()->routeIs('games')" wire:navigate>
                Games
            </x-responsive-nav-link>
            @if (auth()->user()->isSuperAdmin())
                <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')" wire:navigate>
                    Admin
                </x-responsive-nav-link>
            @endif
        </div>
        <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-3">
            <div class="text-sm font-medium text-gray-800 dark:text-gray-200"
                 x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                 x-text="name"
                 x-on:profile-updated.window="name = $event.detail.name"></div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ auth()->user()->email }}</div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>Profile</x-responsive-nav-link>
                <button wire:click="logout" class="w-full text-left">
                    <x-responsive-nav-link>Log Out</x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
