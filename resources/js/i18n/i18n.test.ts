import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { createTranslator, intlLocale } from './context'
import { MH_LOCALES } from './messages'

describe('the languages this package ships', () => {
    /**
     * ⚠️ THE MARKUP IS FROZEN, SO A MISSING KEY IS A WORD NOBODY CAN CHANGE. Shipping a language
     * that covers most of the screen leaves the rest in English, and the only remaining move for
     * whoever needs it translated is to fork the component — which is the outcome the frozen
     * markup exists to prevent.
     */
    it('cover exactly the same keys', () => {
        const [reference, ...others] = Object.entries(MH_LOCALES)

        expect(reference).toBeDefined()

        const expected = Object.keys(reference?.[1].messages ?? {}).sort()

        for (const [name, locale] of others) {
            expect(Object.keys(locale.messages).sort(), `${name} differs`).toEqual(expected)
        }
    })

    it('leave no message empty', () => {
        for (const [name, locale] of Object.entries(MH_LOCALES)) {
            for (const [key, line] of Object.entries(locale.messages)) {
                expect(line.trim(), `${name}.${key}`).not.toBe('')
            }
        }
    })

    /**
     * ⚠️ A COUNTED MESSAGE NEEDS BOTH FORMS IN EVERY LANGUAGE. One that carries a single form is
     * a sentence that reads correctly for one number and wrongly for every other.
     */
    it('give a counted message both of its forms', () => {
        for (const [name, locale] of Object.entries(MH_LOCALES)) {
            for (const [key, line] of Object.entries(locale.messages)) {
                if (!line.includes('{count}')) {
                    continue
                }

                expect(line.split('|'), `${name}.${key}`).toHaveLength(2)
            }
        }
    })
})

describe('choosing a form for a number', () => {
    /**
     * ⚠️ ZERO IS WHERE THE TWO LANGUAGES PART. English says "0 files"; French says
     * « 0 fichier ». A single shared rule is wrong in one of them on every screen that counts.
     */
    it('puts zero in the plural in English and in the singular in French', () => {
        expect(createTranslator('en')('grid.count', {}, 0)).toBe('0 media')
        expect(createTranslator('fr')('grid.count', {}, 0)).toBe('0 fichier')
        expect(createTranslator('fr')('grid.count', {}, 1)).toBe('1 fichier')
        expect(createTranslator('fr')('grid.count', {}, 2)).toBe('2 fichiers')
    })

    it('substitutes what it was given', () => {
        expect(createTranslator('en')('selection.count', {}, 3)).toBe('3 selected')
    })
})

describe('the translator it ships', () => {
    /**
     * ⚠️ AN UNKNOWN KEY COMES BACK AS ITSELF. A blank button says nothing and looks like a
     * rendering fault; `picker.choose` on a button names exactly what is missing, which is what
     * somebody adding a language needs to read.
     */
    it('answers an unknown key with the key', () => {
        expect(createTranslator('en')('nothing.here')).toBe('nothing.here')
    })

    /** ⚠️ AND AN UNKNOWN LANGUAGE FALLS BACK rather than rendering a screen full of keys. */
    it('falls back to a language it has', () => {
        expect(createTranslator('kl')('picker.choose')).toBe('Choose')
    })

    it('takes a whole catalogue of somebody else', () => {
        const theirs = createTranslator({
            messages: { 'picker.choose': 'Prendre' },
            plural: () => 0,
        })

        expect(theirs('picker.choose')).toBe('Prendre')
    })
})

/**
 * NO SENTENCE IS WRITTEN INTO A COMPONENT.
 *
 * ⚠️ THIS IS THE GUARD THAT KEEPS THE RULE ALIVE. Twenty components were written with English
 * defaults before this existed, and three words — Type, Size, Dimensions — ended up in the markup
 * where no prop, no theme and no translation could reach them. Nothing failed; the words were
 * simply unchangeable, and the only way out would have been to fork the component.
 */
describe('no sentence is written into a component', () => {
    const here = join(dirname(fileURLToPath(import.meta.url)), '..', 'components')

    const components = readdirSync(here)
        .filter((name) => name.endsWith('.vue'))
        .map((name) => ({ name, body: readFileSync(join(here, name), 'utf8') }))

    it('has components to check', () => {
        expect(components.length).toBeGreaterThan(0)
    })

    /**
     * Text between tags that is not an interpolation, a comment or an entity.
     *
     * ⚠️ IT LOOKS AT THE MARKUP ONLY. A word in a comment explains a decision; the same word
     * between two tags is on somebody's screen.
     */
    function sentences(body: string): string[] {
        const markup = body.slice(body.indexOf('<template>')).replace(/<!--[\s\S]*?-->/g, '')

        return (markup.match(/>[^<>{}]+</g) ?? [])
            .map((match) => match.slice(1, -1).trim())
            .filter((text) => /[A-Za-z]{2}/.test(text))
    }

    it.each(components.map((component) => component.name))('%s writes no sentence', (name) => {
        const component = components.find((candidate) => candidate.name === name)

        expect(sentences(component?.body ?? '')).toEqual([])
    })

    /** ⚠️ AND THE GUARD ITSELF HAS TO BE ABLE TO SAY NO — it reads files, so nothing else would. */
    it('recognises a sentence when it sees one', () => {
        expect(sentences('<template><p>Nothing here yet</p></template>')).toEqual(['Nothing here yet'])
        expect(sentences('<template><p>{{ words.empty }}</p></template>')).toEqual([])
        expect(sentences('<template><!-- A remark in English --><p>{{ x }}</p></template>')).toEqual([])
    })
})

describe('a language tag Intl will accept', () => {
    /**
     * ⚠️ `fr_FR` IS THE SHAPE PHP AND LARAVEL CARRY EVERYWHERE, and `Intl` throws a `RangeError`
     * on it — BCP 47 wants a hyphen. A host handing us its application locale would otherwise
     * take a whole panel down for the sake of a date.
     */
    it('accepts the underscore that every PHP application uses', () => {
        expect(intlLocale('fr_FR')).toBe('fr-FR')
    })

    it('leaves a tag that was already right alone', () => {
        expect(intlLocale('fr')).toBe('fr')
    })

    /** ⚠️ AND ANYTHING UNPARSEABLE BECOMES `undefined`, which `Intl` reads as "use your own". */
    it('answers nothing rather than throwing on something that is not a tag', () => {
        expect(intlLocale('not a locale at all')).toBeUndefined()
        expect(intlLocale('')).toBeUndefined()
        expect(intlLocale(undefined)).toBeUndefined()
    })

    /** ⚠️ THE GUARD ITSELF HAS TO BE ABLE TO SAY NO — otherwise it reports every tag as fine. */
    it('proves it by refusing one', () => {
        expect(() => new Intl.DateTimeFormat('fr_FR')).toThrow()
        expect(() => new Intl.DateTimeFormat(intlLocale('fr_FR'))).not.toThrow()
    })
})
