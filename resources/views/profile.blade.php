<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Profile</h2>
    </x-slot>

    <div class="space-y-6 max-w-2xl">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <livewire:profile.update-profile-information-form />
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <livewire:profile.update-password-form />
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <livewire:profile.delete-user-form />
        </div>
    </div>
</x-app-layout>
