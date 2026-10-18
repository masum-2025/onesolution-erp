import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { writeFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';

// Unit tests need neither Laravel's asset plugin nor its CI check (which
// refuses to start under CI, taking the test run for a dev server).
const unitTests = Boolean(process.env.VITEST);

// Each build has its own id: compiled into the app (__BUILD_ID__) and written to
// public/build/version.json, so an open tab can tell a newer release is out
// (resources/js/lib/appVersion.js) and the service worker keeps one cache per build.
const BUILD_ID = Date.now().toString(36);
const buildVersion = {
    name: 'build-version',
    apply: 'build',
    writeBundle(options) {
        writeFileSync(fileURLToPath(new URL('./public/build/version.json', import.meta.url)), `${JSON.stringify({ build: BUILD_ID })}\n`);
    },
};

export default defineConfig({
    define: { __BUILD_ID__: JSON.stringify(BUILD_ID) },
    plugins: [
        buildVersion,
        !unitTests &&
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    build: {
        // Budget is checked by scripts/check-bundle-size.js after every build.
        chunkSizeWarningLimit: 250,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
    },
});
