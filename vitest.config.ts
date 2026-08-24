import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

/*
 * ⚠️ THE FLOOR IS DECLARED HERE, NOT ONLY IN THE PIPELINE. A threshold that lives only in CI is
 * one a contributor discovers on a pull request, after the work is done. Declared in the
 * configuration, `npm run test:coverage` refuses locally for the same reason and at the same
 * number.
 *
 * ⚠️ TWO PROJECTS, AND THE SPLIT IS ITSELF A GUARANTEE. Components need a document; layers 1 and
 * 2 must not. Running everything under a DOM would be simpler and would quietly destroy the one
 * property that lets an Angular application consume this package: the day something in the core
 * reaches for `window`, nothing would turn red. Keeping the core in `node` is what makes that
 * mistake impossible to commit.
 */
export default defineConfig({
    test: {
        projects: [
            {
                test: {
                    name: 'core',
                    environment: 'node',
                    include: [
                        'resources/js/*.test.ts',
                        'resources/js/client/**/*.test.ts',
                        'resources/js/vue/**/*.test.ts',
                        'resources/js/theme/**/*.test.ts',
                    ],
                },
            },
            {
                plugins: [vue()],
                test: {
                    name: 'components',
                    environment: 'happy-dom',
                    include: ['resources/js/components/**/*.test.ts'],
                },
            },
        ],
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.ts', 'resources/js/**/*.vue'],
            exclude: ['resources/js/**/*.test.ts', 'resources/js/**/*.test-utils.ts'],
            /*
             * ⚠️ PER FILE, NOT A SUMMARY. A total above the floor says nothing about which file
             * is carrying which: one module at 40 % hides behind nine at 98 %, and the number
             * that would have prompted a test is the one the summary removes.
             */
            reporter: ['text'],
            thresholds: {
                lines: 85,
                functions: 85,
                branches: 85,
                statements: 85,
            },
        },
    },
})
