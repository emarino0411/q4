@props(['active'])

@php
$classes = ($active ?? false)
    ? 'block px-4 py-2 text-sm font-medium text-wimbledon-600 dark:text-wimbledon-400 bg-wimbledon-50 dark:bg-wimbledon-900/30 border-l-2 border-wimbledon-600 dark:border-wimbledon-400'
    : 'block px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-50 dark:hover:bg-gray-800 border-l-2 border-transparent transition duration-150';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
