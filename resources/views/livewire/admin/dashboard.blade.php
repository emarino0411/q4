<?php

use App\Models\User;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app', ['header' => 'Admin Dashboard'])] class extends Component
{
    public string $search = '';

    // Create user modal
    public string $newName     = '';
    public string $newEmail    = '';
    public string $newPassword = '';
    public string $newRole     = 'admin';

    // Edit role modal
    public ?int $editingUserId   = null;
    public string $editingRole   = '';
    public string $editingName   = '';

    // Delete modal
    public ?int $deletingUserId   = null;
    public string $deletingName   = '';

    public function with(): array
    {
        $users = User::withCount('games')
            ->when($this->search, fn($q) => $q->where('name', 'like', '%' . $this->search . '%')
                ->orWhere('email', 'like', '%' . $this->search . '%'))
            ->orderByRaw("FIELD(role, 'super_admin', 'admin', 'player')")
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                $gameIds = $user->games()->pluck('id');
                $matches = GameMatch::whereHas('court', fn($q) => $q->whereIn('game_id', $gameIds))
                    ->where('status', 'finished')
                    ->count();
                return ['user' => $user, 'matches' => $matches];
            });

        $totals = [
            'users'       => User::count(),
            'super_admins' => User::where('role', 'super_admin')->count(),
            'admins'      => User::where('role', 'admin')->count(),
            'players'     => User::where('role', 'player')->count(),
        ];

        return compact('users', 'totals');
    }

    public function openCreate(): void
    {
        $this->newName = $this->newEmail = $this->newPassword = '';
        $this->newRole = 'admin';
        $this->dispatch('open-modal', 'create-user');
    }

    public function createUser(): void
    {
        $this->validate([
            'newName'     => ['required', 'string', 'max:255'],
            'newEmail'    => ['required', 'email', 'unique:users,email'],
            'newPassword' => ['required', 'string', 'min:8'],
            'newRole'     => ['required', 'in:super_admin,admin,player'],
        ]);

        User::create([
            'name'     => $this->newName,
            'email'    => $this->newEmail,
            'password' => Hash::make($this->newPassword),
            'role'     => $this->newRole,
        ]);

        $this->newName = $this->newEmail = $this->newPassword = '';
        $this->dispatch('close-modal', 'create-user');
    }

    public function openEdit(int $userId): void
    {
        $user = User::findOrFail($userId);
        $this->editingUserId = $userId;
        $this->editingName   = $user->name;
        $this->editingRole   = $user->role;
        $this->dispatch('open-modal', 'edit-user');
    }

    public function updateUser(): void
    {
        $this->validate([
            'editingName' => ['required', 'string', 'max:255'],
            'editingRole' => ['required', 'in:super_admin,admin,player'],
        ]);

        $user = User::findOrFail($this->editingUserId);

        // Prevent removing the last super admin
        if ($user->isSuperAdmin() && $this->editingRole !== 'super_admin') {
            $remaining = User::where('role', 'super_admin')->where('id', '!=', $user->id)->count();
            abort_unless($remaining > 0, 422, 'Cannot remove the last super admin.');
        }

        $user->update(['name' => $this->editingName, 'role' => $this->editingRole]);
        $this->editingUserId = null;
        $this->dispatch('close-modal', 'edit-user');
    }

    public function openDelete(int $userId): void
    {
        $user = User::findOrFail($userId);
        abort_if($user->id === Auth::id(), 403);
        $this->deletingUserId = $userId;
        $this->deletingName   = $user->name;
        $this->dispatch('open-modal', 'delete-user');
    }

    public function deleteUser(): void
    {
        $user = User::findOrFail($this->deletingUserId);
        abort_if($user->id === Auth::id(), 403);

        if ($user->isSuperAdmin()) {
            $remaining = User::where('role', 'super_admin')->where('id', '!=', $user->id)->count();
            abort_unless($remaining > 0, 422);
        }

        $user->delete();
        $this->deletingUserId = null;
        $this->deletingName   = '';
        $this->dispatch('close-modal', 'delete-user');
    }
}; ?>

<div class="space-y-6">

    {{-- Stats row --}}
    <div class="grid grid-cols-4 gap-4">
        @foreach ([
            ['label' => 'Total Users',   'value' => $totals['users'],        'color' => 'text-gray-900 dark:text-white'],
            ['label' => 'Super Admins',  'value' => $totals['super_admins'], 'color' => 'text-red-600 dark:text-red-400'],
            ['label' => 'Admins',        'value' => $totals['admins'],       'color' => 'text-wimbledon-600 dark:text-wimbledon-400'],
            ['label' => 'Players',       'value' => $totals['players'],      'color' => 'text-blue-600 dark:text-blue-400'],
        ] as $stat)
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 px-6 py-5">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">{{ $stat['label'] }}</p>
                <p class="text-3xl font-bold {{ $stat['color'] }}">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Users table --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white shrink-0">Users</h3>
            <input wire:model.live="search" type="text" placeholder="Search name or email…"
                class="flex-1 max-w-xs text-sm rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-wimbledon-500" />
            <button wire:click="openCreate" class="shrink-0 text-sm px-3 py-1.5 rounded-lg bg-wimbledon-600 hover:bg-wimbledon-700 text-white font-medium transition">
                + New User
            </button>
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <th class="px-6 py-3 text-left">Name</th>
                    <th class="px-6 py-3 text-left">Email</th>
                    <th class="px-6 py-3 text-left">Role</th>
                    <th class="px-6 py-3 text-center">Games</th>
                    <th class="px-6 py-3 text-center">Matches</th>
                    <th class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($users as $row)
                    @php
                        $u = $row['user'];
                        $roleBadge = match($u->role) {
                            'super_admin' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
                            'admin'       => 'bg-wimbledon-100 text-wimbledon-700 dark:bg-wimbledon-900/40 dark:text-wimbledon-400',
                            'player'      => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                            default       => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                        };
                        $roleLabel = match($u->role) {
                            'super_admin' => 'Super Admin',
                            'admin'       => 'Admin',
                            'player'      => 'Player',
                            default       => $u->role,
                        };
                    @endphp
                    <tr class="bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                        <td class="px-6 py-3 font-medium text-gray-900 dark:text-white">
                            {{ $u->name }}
                            @if ($u->id === auth()->id())
                                <span class="ml-1 text-xs text-gray-400">(you)</span>
                            @endif
                        </td>
                        <td class="px-6 py-3 text-gray-500 dark:text-gray-400">{{ $u->email }}</td>
                        <td class="px-6 py-3">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $roleBadge }}">{{ $roleLabel }}</span>
                        </td>
                        <td class="px-6 py-3 text-center text-gray-500 dark:text-gray-400">{{ $u->games_count }}</td>
                        <td class="px-6 py-3 text-center text-gray-500 dark:text-gray-400">{{ $row['matches'] }}</td>
                        <td class="px-6 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button wire:click="openEdit({{ $u->id }})" class="text-xs text-wimbledon-600 dark:text-wimbledon-400 hover:underline">Edit</button>
                                @if ($u->id !== auth()->id())
                                    <button wire:click="openDelete({{ $u->id }})" class="text-xs text-red-500 dark:text-red-400 hover:underline">Delete</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-400 dark:text-gray-500">No users found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create User Modal --}}
    <x-modal name="create-user" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">New User</h3>
                <button x-on:click="$dispatch('close-modal', 'create-user')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="space-y-3 mb-5">
                <div>
                    <x-input-label value="Name" />
                    <x-text-input wire:model="newName" type="text" class="w-full mt-1" placeholder="Full name" />
                    <x-input-error :messages="$errors->get('newName')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Email" />
                    <x-text-input wire:model="newEmail" type="email" class="w-full mt-1" placeholder="email@example.com" />
                    <x-input-error :messages="$errors->get('newEmail')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Password" />
                    <x-text-input wire:model="newPassword" type="password" class="w-full mt-1" placeholder="Min 8 characters" />
                    <x-input-error :messages="$errors->get('newPassword')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Role" />
                    <select wire:model="newRole" class="w-full mt-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-wimbledon-500">
                        <option value="player">Player</option>
                        <option value="admin">Admin</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'create-user')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <button wire:click="createUser" class="flex-1 py-2 rounded-lg bg-wimbledon-600 hover:bg-wimbledon-700 text-white text-sm font-medium transition">Create</button>
            </div>
        </div>
    </x-modal>

    {{-- Edit User Modal --}}
    <x-modal name="edit-user" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Edit User</h3>
                <button x-on:click="$dispatch('close-modal', 'edit-user')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="space-y-3 mb-5">
                <div>
                    <x-input-label value="Name" />
                    <x-text-input wire:model="editingName" type="text" class="w-full mt-1" />
                    <x-input-error :messages="$errors->get('editingName')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="Role" />
                    <select wire:model="editingRole" class="w-full mt-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-wimbledon-500">
                        <option value="player">Player</option>
                        <option value="admin">Admin</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                    <x-input-error :messages="$errors->get('editingRole')" class="mt-1" />
                </div>
            </div>
            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'edit-user')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <button wire:click="updateUser" class="flex-1 py-2 rounded-lg bg-wimbledon-600 hover:bg-wimbledon-700 text-white text-sm font-medium transition">Save</button>
            </div>
        </div>
    </x-modal>

    {{-- Delete User Modal --}}
    <x-modal name="delete-user" maxWidth="sm">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete User</h3>
                <button x-on:click="$dispatch('close-modal', 'delete-user')" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Delete <strong class="text-gray-900 dark:text-white">{{ $deletingName }}</strong>?</p>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">Their games and all associated data will be permanently removed.</p>
            <div class="flex gap-3">
                <button x-on:click="$dispatch('close-modal', 'delete-user')" class="flex-1 py-2 rounded-lg border border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">Cancel</button>
                <button wire:click="deleteUser" class="flex-1 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition">Delete</button>
            </div>
        </div>
    </x-modal>

</div>
