@props(['active'])

@php
$classes = ($active ?? false)
    ? 'text-sm font-medium text-wimbledon-600 dark:text-wimbledon-400 border-b-2 border-wimbledon-600 dark:border-wimbledon-400 pb-0.5'
    : 'text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white border-b-2 border-transparent pb-0.5 transition duration-150';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
