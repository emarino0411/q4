<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center px-4 py-2 bg-wimbledon-600 text-white text-sm font-medium rounded-lg hover:bg-wimbledon-700 focus:outline-none focus:ring-2 focus:ring-wimbledon-500 focus:ring-offset-2 disabled:opacity-50 transition duration-150']) }}>
    {{ $slot }}
</button>
