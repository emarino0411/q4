<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate(['password' => ['required', 'string', 'current_password']]);
        tap(Auth::user(), $logout(...))->delete();
        $this->redirect('/', navigate: true);
    }
}; ?>

<section>
    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Delete Account</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-6">Once deleted, all your data will be permanently removed.</p>

    <x-danger-button x-data x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
        Delete Account
    </x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Are you sure?</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-6">This action cannot be undone. Please enter your password to confirm.</p>

            <div class="mb-6">
                <x-input-label for="delete_password" value="Password" class="sr-only" />
                <x-text-input wire:model="password" id="delete_password" name="password" type="password" placeholder="Enter your password" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button>Delete Account</x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
