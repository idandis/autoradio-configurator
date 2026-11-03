import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { copyFileSync } from 'node:fs';

export default defineConfig({
    build: {
        rollupOptions: {
            output: {
                // Keep releases isolated from partially uploaded or stale chunks.
                chunkFileNames: 'assets/[name]-v2-[hash].js',
            },
        },
    },
    plugins: [
        {
            name: 'build-server-headers',
            apply: 'build',
            closeBundle() {
                copyFileSync(new URL('./resources/deploy/build.htaccess', import.meta.url), new URL('./public/build/.htaccess', import.meta.url));
            },
        },
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
