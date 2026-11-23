import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig(({ command }) => ({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/site.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            publicDirectory: '.',
            buildDirectory: 'dist',
            hotFile: 'hot',
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    // the published address applies to the build only: the dev server serves from its root (the hot file's address)
    base: command === 'build' ? '/vendor/hata/' : '',
    publicDir: 'resources/static',
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
    },
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
}));
