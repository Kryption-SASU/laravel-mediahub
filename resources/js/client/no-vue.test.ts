import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * LAYER 1 MAY NOT IMPORT VUE.
 *
 * ⚠️ A CONVENTION NOBODY CHECKS LASTS UNTIL THE FIRST HURRY. The whole reason this layer exists
 * is that an Angular application must be able to use the typed client and the upload queue
 * without pulling a second framework into its bundle. One `import { ref } from 'vue'` added in
 * passing takes that away, and nothing else in the suite would notice: the tests would stay
 * green, and the cost would show up as a bundle size on somebody else's project.
 *
 * ⚠️ AND IT READS THE SOURCES RATHER THAN THE MODULE GRAPH. Importing the barrel and inspecting
 * what it pulled would miss a type-only import, which `verbatimModuleSyntax` erases at build
 * time but which still couples the code to Vue's types.
 */
describe('the core stays free of Vue', () => {
    const here = dirname(fileURLToPath(import.meta.url))

    const sources = readdirSync(here)
        .filter((name) => name.endsWith('.ts') && !name.endsWith('.test.ts'))
        .map((name) => ({ name, body: readFileSync(join(here, name), 'utf8') }))

    it('has sources to check', () => {
        expect(sources.length).toBeGreaterThan(0)
    })

    it.each(sources.map((source) => source.name))('%s does not import vue', (name) => {
        const source = sources.find((candidate) => candidate.name === name)

        expect(source?.body).not.toMatch(/from\s+['"]vue['"]/)
        expect(source?.body).not.toMatch(/import\s*\(\s*['"]vue['"]\s*\)/)
        expect(source?.body).not.toMatch(/require\(\s*['"]vue['"]\s*\)/)
    })
})
