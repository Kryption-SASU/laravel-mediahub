import { fileURLToPath } from 'node:url'
import tailwind from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

/**
 * THE STANDALONE BUNDLE — one `.js` and one `.css`, Vue included.
 *
 * ⚠️ THIS EXISTS FOR A LARAVEL APPLICATION WITH NO BUILD AT ALL. `composer require` has to be
 * enough to get a working screen, and a Composer package ships what is in the repository: there
 * is no install step that could compile anything. Either this artefact is versioned, or the
 * standalone mode does not exist.
 *
 * ⚠️ AND IT IS BUILT AT A TAG, NEVER ON A BRANCH. A bundle committed to something that moves is
 * stale from the next commit onwards, in a way nothing reports — the screen simply behaves like
 * an older version of itself. The pipeline rebuilds it on a tag and compares; a difference fails
 * the release, because a versioned artefact nobody checks is worth less than none.
 */
export default defineConfig({
    plugins: [vue(), tailwind()],

    /*
     * ⚠️ `process.env.NODE_ENV` IS NOT REPLACED BY `build.lib` ON ITS OWN, and Vue's runtime
     * reads it. Left alone, the bundle carries the development build into production: warnings,
     * the devtools hooks, and several times the size — with nothing anywhere saying so.
     */
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },

    build: {
        lib: {
            entry: fileURLToPath(new URL('./resources/js/standalone.ts', import.meta.url)),
            name: 'MediaHub',

            /*
             * ⚠️ ONE FORMAT, AND NOT `es`. Vite refuses to minify an `es` library build, so the
             * artefact would ship unminified while the configuration says otherwise. `umd` also
             * loads from a plain `<script>` tag, which is the only thing an application with no
             * bundler can do.
             */
            formats: ['umd'],
            fileName: () => 'mediahub.js',
        },

        outDir: 'dist',
        emptyOutDir: true,
        cssCodeSplit: false,

        /*
         * ⚠️ THE BUNDLED MINIFIER, NOT ONE INSTALLED BESIDE IT. This build is rerun at a tag and
         * compared byte for byte with the committed artefact, so anything that could differ
         * between two machines makes the comparison report a difference nobody introduced.
         */
        minify: true,

        rollupOptions: {
            output: {
                assetFileNames: 'mediahub.[ext]',
            },
        },
    },
})
