<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-wimbledon-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900 disabled:opacity-50 transition duration-150']) }}>
    {{ $slot }}
</button>
