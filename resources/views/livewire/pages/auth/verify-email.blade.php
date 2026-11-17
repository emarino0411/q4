<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
            return;
        }

        Auth::user()->sendEmailVerificationNotification();
        Session::flash('status', 'verification-link-sent');
    }

    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold text-gray-900 mb-1">Verify your email</h1>
    <p class="text-sm text-gray-500 mb-6">
        Thanks for signing up! Please verify your email address by clicking the link we sent you.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 text-sm text-green-600 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
            A new verification link has been sent to your email address.
        </div>
    @endif

    <div class="space-y-3">
        <x-primary-button wire:click="sendVerification" class="w-full">
            Resend Verification Email
        </x-primary-button>

        <button wire:click="logout" class="w-full text-sm text-gray-500 hover:text-gray-700 transition">
            Log Out
        </button>
    </div>
</div>
