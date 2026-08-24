import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * NO CLASS IS WRITTEN INTO A TEMPLATE.
 *
 * ⚠️ THE CLASS TABLE IS THE ONLY REASON THESE COMPONENTS DO NOT NEED TO BE FORKED. One
 * `class="rounded-md"` typed into a template in a hurry is a piece of appearance no host can
 * reach: not through a token, not through a theme, not through the `ui` prop. Nothing breaks,
 * nothing turns red, and the host's only remaining option is to copy the component — which is
 * exactly the failure this design exists to prevent.
 *
 * ⚠️ AND IT READS THE FILES RATHER THAN THE RENDERED OUTPUT. A rendered component shows the
 * classes it happens to produce for the props it was given; a branch nobody exercised would keep
 * its hardcoded string and this guard would say nothing.
 */
describe('the appearance stays in the theme', () => {
    const here = dirname(fileURLToPath(import.meta.url))

    const templates = readdirSync(here)
        .filter((name) => name.endsWith('.vue'))
        .map((name) => ({ name, body: readFileSync(join(here, name), 'utf8') }))

    it('has components to check', () => {
        expect(templates.length).toBeGreaterThan(0)
    })

    /**
     * WHAT IS FORBIDDEN IS A LITERAL, NOT A BINDING.
     *
     * ⚠️ `:class="confirmClass"` IS PERFECTLY LEGITIMATE — the computed behind it reads the
     * table like everything else. A rule phrased as "the attribute must contain `cls(`" would
     * reject it, and a guard that cries wolf on correct code is a guard somebody deletes.
     *
     * So: the theme lookups are removed first, and what must remain is nothing quoted at all.
     */
    function offendingClasses(markup: string): string[] {
        const attributes = markup.match(/(?:^|\s):?class\s*=\s*"[^"]*"/g) ?? []

        return attributes.filter((attribute) => {
            const isBinding = /\s:class/.test(attribute)
            const value = attribute.slice(attribute.indexOf('"') + 1, -1)
            const withoutLookups = value.replace(/\bcls\(\s*'[^']*'\s*\)/g, '')

            return isBinding ? /['"`]/.test(withoutLookups) : /[A-Za-z]/.test(withoutLookups)
        })
    }

    it.each(templates.map((template) => template.name))('%s writes no class of its own', (name) => {
        const template = templates.find((candidate) => candidate.name === name)
        const body = template?.body ?? ''
        const markup = body.slice(body.indexOf('<template>'))

        expect(offendingClasses(markup)).toEqual([])
    })

    /**
     * ⚠️ AND THE GUARD ITSELF HAS TO BE ABLE TO SAY NO. It reads files rather than behaviour, so
     * nothing else would notice if a change to the pattern quietly stopped matching anything —
     * it would keep reporting that every component is clean, forever.
     */
    it('recognises a hardcoded class when it sees one', () => {
        expect(offendingClasses('<template><div class="rounded-md bg-white" /></template>')).toHaveLength(1)
        expect(offendingClasses(`<template><div :class="'p-4'" /></template>`)).toHaveLength(1)
        expect(offendingClasses(`<template><div :class="cls('root')" /></template>`)).toEqual([])
        expect(offendingClasses('<template><div :class="confirmClass" /></template>')).toEqual([])
    })

    /**
     * ⚠️ AND THE DEFAULT SKIN IS NOT ALLOWED TO HIDE IN THE SCRIPT EITHER. Computing a class
     * string in `<script setup>` puts it just as far out of a host's reach as writing it in the
     * markup, and looks tidier while doing it.
     */
    it.each(templates.map((template) => template.name))('%s builds no class in its script', (name) => {
        const template = templates.find((candidate) => candidate.name === name)
        const body = template?.body ?? ''
        const script = body.slice(0, body.indexOf('<template>'))

        /* Tailwind-shaped literals: a quoted string of utility-looking words. */
        expect(script).not.toMatch(/['"`](?:[a-z-]+:)?(?:flex|grid|rounded|bg-|text-|p-|m-|w-|h-)[a-z0-9-]*/)
    })
})
