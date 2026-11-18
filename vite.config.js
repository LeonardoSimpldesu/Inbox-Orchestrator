import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
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
        fs: {
            // Only the frontend sources and dependencies may be served. Anything
            // else in the project (database, storage/logs, bootstrap/cache with
            // APP_KEY, PHP sources, vendor) must never be reachable.
            strict: true,
            allow: ['resources', 'node_modules'],
            deny: ['.env', '.env.*', '*.{crt,pem}', '**/.git/**', '*.sqlite*', '**/storage/**', '**/bootstrap/cache/**'],
        },
        hmr: {
            host: 'localhost',
        },
    },
});
