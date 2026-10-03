import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },

    server: {
        host: '0.0.0.0',
        port: 5173,
        allowedHosts: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        hmr: {
            host: 'localhost',
        },
    },

    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.test.js'],
    },
});