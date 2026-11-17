import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                wimbledon: {
                    50:  '#f0faf4',
                    100: '#d6f0e0',
                    200: '#aee0c2',
                    300: '#76c99a',
                    400: '#3dac6f',
                    500: '#1a8f52',
                    600: '#046A38',
                    700: '#035a2f',
                    800: '#034a27',
                    900: '#023d20',
                },
            },
        },
    },

    plugins: [forms],
};
