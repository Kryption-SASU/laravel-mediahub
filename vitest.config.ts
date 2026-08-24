import { defineConfig } from 'vitest/config'

/*
 * ⚠️ THE FLOOR IS DECLARED HERE, NOT ONLY IN THE PIPELINE. A threshold that lives only in CI is
 * one a contributor discovers on a pull request, after the work is done. Declared in the
 * configuration, `npm run test:coverage` refuses locally for the same reason and at the same
 * number.
 *
 * ⚠️ AND THE ENVIRONMENT IS `node`, DELIBERATELY. Nothing in layers 1 and 2 touches the DOM: the
 * upload transport is injected, and Vue's reactivity runs perfectly well without a document. A
 * `jsdom` here would let a DOM dependency slip into code that Angular is meant to consume.
 */
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.ts'],
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
