import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/alpine.js', 'resources/js/signal-drawer.js', 'resources/js/signal-theme-init.js', 'resources/js/signal-theme.js'],
            refresh: true,
        }),
    ],
});
