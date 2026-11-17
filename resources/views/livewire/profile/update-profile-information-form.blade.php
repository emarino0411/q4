<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $this->name  = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
        $this->dispatch('profile-updated', name: $user->name);
    }

    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));
            return;
        }

        $user->sendEmailVerificationNotification();
        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section>
    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Profile Information</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-6">Update your name and email address.</p>

    <form wire:submit="updateProfileInformation" class="space-y-4">
        <div>
            <x-input-label for="name" value="Name" />
            <x-text-input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input wire:model="email" id="email" name="email" type="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-1" />

            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <p class="text-sm text-gray-600 mt-2">
                    Your email is unverified.
                    <button wire:click.prevent="sendVerification" class="text-indigo-600 hover:text-indigo-800 underline">Resend verification email.</button>
                </p>
                @if (session('status') === 'verification-link-sent')
                    <p class="text-sm text-green-600 mt-1">Verification link sent!</p>
                @endif
            @endif
        </div>

        <div class="flex items-center gap-3">
            <x-primary-button>Save</x-primary-button>
            <x-action-message on="profile-updated" class="text-sm text-gray-500">Saved.</x-action-message>
        </div>
    </form>
</section>
