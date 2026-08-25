import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Selection } from '../client'
import { MediaHubError } from '../client'
import { deferred, fakeClient, folder, media } from '../vue/fake.test-utils'
import type { MhAction } from './actions'
import { useMediaActionList } from './actions'
import MhFolderList from './MhFolderList.vue'
import MhItemCard from './MhItemCard.vue'
import MhItemGrid from './MhItemGrid.vue'
import MhLightbox from './MhLightbox.vue'
import MhRenamer from './MhRenamer.vue'
import MhSelectionBar from './MhSelectionBar.vue'
import type { MhRenameTarget } from './renaming'
import { useActionRunner } from './useActionRunner'

const oneFile: Selection = { media: ['m1'] }
const oneFolder: Selection = { folders: ['f1'] }

const surfaces = { preview: () => {}, rename: () => {} }

function ids(selection: Selection, where = { trashed: false, picking: false }) {
    const list = useMediaActionList(
        fakeClient(),
        () => selection,
        undefined,
        () => where,
        () => surfaces,
    )

    return list.available.value.map((action) => action.id)
}

function actionOn(api: ReturnType<typeof fakeClient>, id: string, selection = oneFile) {
    const list = useMediaActionList(
        api,
        () => selection,
        undefined,
        () => ({ trashed: false, picking: false }),
        () => surfaces,
    )

    return list.all.value.find((action) => action.id === id)!
}

/**
 * ⚠️ THE CLICK IS CAUGHT AND STOPPED. An anchor click on a real address makes jsdom complain
 * about navigation it has not implemented, over and over, in the middle of the run — and what is
 * being checked is the anchor that was built, which is all the browser is ever given.
 */
function catchDownloads(): { href?: string; name?: string }[] {
    const caught: { href?: string; name?: string }[] = []

    const listener = (event: Event): void => {
        const anchor = event.target as HTMLAnchorElement

        if (anchor?.tagName === 'A') {
            event.preventDefault()
            caught.push({ href: anchor.getAttribute('href') ?? undefined, name: anchor.download })
        }
    }

    document.addEventListener('click', listener)
    stopListening = () => document.removeEventListener('click', listener)

    return caught
}

let stopListening: (() => void) | null = null

afterEach(() => {
    stopListening?.()
    stopListening = null
})

describe('what can be done to one thing', () => {
    /**
     * ⚠️ HALF THESE ACTS HAVE NO PLURAL. A file cannot be renamed to two names, duplicating four
     * files is four acts rather than one, and "Preview" on a batch would have to pick one and
     * say nothing about the rest. They are offered on one thing, and only one.
     */
    it('offers the single-item entries on one file', () => {
        expect(ids(oneFile)).toEqual([
            'preview',
            'link',
            'rename',
            'duplicate',
            'download',
            'trash',
        ])
    })

    it('offers none of them on two', () => {
        expect(ids({ media: ['m1', 'm2'] })).toEqual(['trash'])
    })

    /**
     * ⚠️ A FOLDER ALONGSIDE MAKES THE ANSWER NOTHING, rather than being quietly ignored.
     * "Download" offered on a file and a folder together would take the file, say nothing about
     * the folder, and look like it had obeyed.
     */
    it('offers none of them on a file with a folder beside it', () => {
        expect(ids({ media: ['m1'], folders: ['f1'] })).toEqual(['trash'])
    })

    /** ⚠️ RENAMING IS THE ONE THAT MEANS THE SAME THING ON A FOLDER, and it was reachable from
     * nowhere at all. */
    it('offers renaming on a folder, and nothing else', () => {
        expect(ids(oneFolder)).toEqual(['rename', 'trash'])
    })

    /** ⚠️ THE TRASH OFFERS TWO THINGS AND MEANS THEM. Renaming something on its way out, or
     * duplicating it, are not among them. */
    it('offers none of them in the trash', () => {
        expect(ids(oneFile, { trashed: true, picking: false })).toEqual(['restore', 'purge'])
    })

    it('offers none of them while a batch is being built', () => {
        expect(ids(oneFile, { trashed: false, picking: true })).toEqual(['trash'])
    })
})

describe('the address of a file', () => {
    /**
     * ⚠️ THE ADDRESS IS ASKED FOR, NOT ASSEMBLED. A URL built here from an identifier is right
     * until a host serves its files from elsewhere — a CDN, a signed route, a disk that answers
     * with links of its own — and wrong in a way nobody notices until somebody pastes one.
     */
    it('copies the address the server gives for it', async () => {
        const written: string[] = []

        Object.defineProperty(navigator, 'clipboard', {
            value: {
                writeText: (text: string) => {
                    written.push(text)

                    return Promise.resolve()
                },
            },
            configurable: true,
        })

        const api = fakeClient()

        await actionOn(api, 'link').run(oneFile)

        expect(api.calls.filter((call) => call.method === 'show')).toHaveLength(1)
        expect(written).toEqual(['/media/m1/file'])
    })

    /**
     * ⚠️ `navigator.clipboard` DOES NOT EXIST OUTSIDE A SECURE CONTEXT, which is every `http://`
     * development host there is. Without the older command behind it, the entry would do nothing
     * at all in the one environment everybody works in — silently.
     */
    it('falls back to the editing command where there is no clipboard', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })

        const copied: string[] = []

        Object.defineProperty(document, 'execCommand', {
            value: () => {
                /* ⚠️ WHAT IS IN THE DOCUMENT AT THAT MOMENT, not what has focus. The command
                 * copies the document's selection, so the stand-in has to be in the tree while
                 * it runs; whether selecting a field also focuses it is the browser's business,
                 * and jsdom's answer is not the one people work in. */
                const area = document.querySelector('textarea[aria-hidden="true"]')

                copied.push((area as HTMLTextAreaElement | null)?.value ?? '(nothing)')

                return true
            },
            configurable: true,
        })

        /* ⚠️ AND IT COPIES THE ADDRESS, NOT WHATEVER THE PAGE HAD SELECTED. The command acts on
         * the document's selection, so the stand-in has to be in the tree and focused — built
         * off-tree it would copy nothing, and the entry would still say it had worked. */
        await actionOn(fakeClient(), 'link').run(oneFile)

        expect(copied).toEqual(['/media/m1/file'])
    })

    /** ⚠️ AND THE STAND-IN DOES NOT STAY BEHIND. One per copy, on a screen somebody uses all
     * day, is a document growing a textarea at a time. */
    it('leaves nothing behind in the document', async () => {
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })
        Object.defineProperty(document, 'execCommand', { value: () => true, configurable: true })

        const before = document.querySelectorAll('textarea').length

        await actionOn(fakeClient(), 'link').run(oneFile)

        expect(document.querySelectorAll('textarea')).toHaveLength(before)
    })
})

describe('duplicating and downloading', () => {
    /** ⚠️ NO TARGET MEANS "WHERE IT ALREADY IS". Duplicating is not moving, and a copy that
     * lands at the root is one nobody sees happen. */
    it('duplicates a file where it already sits', async () => {
        const api = fakeClient()

        await actionOn(api, 'duplicate').run(oneFile)

        expect(api.calls.filter((call) => call.method === 'copy')).toEqual([
            { method: 'copy', args: ['m1', null] },
        ])
    })

    /**
     * ⚠️ THE FILE NAME IS CARRIED, because the address rarely holds it. A signed URL ends in a
     * signature and a CDN in a hash: without it, a download of `m1.pdf` lands in the folder
     * named after the hash, which is the sort of thing nobody reports and everybody works
     * around.
     */
    it('hands the browser the download address and the name', async () => {
        const caught = catchDownloads()

        await actionOn(fakeClient(), 'download').run(oneFile)

        expect(caught).toEqual([{ href: '/media/m1/download', name: 'm1.pdf' }])
    })

    /** ⚠️ AND THE DOWNLOAD ADDRESS, NOT THE ONE THE VIEWER USES. They are two routes and only
     * one of them answers with an attachment header. */
    it('does not hand it the address used for showing', async () => {
        const caught = catchDownloads()

        await actionOn(fakeClient(), 'download').run(oneFile)

        expect(caught[0]?.href).not.toBe('/media/m1/file')
    })
})

describe('one file, as large as the screen allows', () => {
    /* ⚠️ A CLIENT, EVEN FOR A PICTURE. The viewer needs one to build the route a document is
     * read through, and it is resolved at setup like everywhere else in this package — a
     * component that asked for it only when it happened to need one could not inject it. */
    function viewer(props: Record<string, unknown> = {}) {
        return mount(MhLightbox, {
            props: {
                media: media('m1', { type: 'image', url: '/media/m1/file' }),
                client: fakeClient(),
                ...props,
            },
            attachTo: document.body,
        })
    }

    function isOpen(wrapper: ReturnType<typeof mount>): boolean {
        return (wrapper.find('dialog').element as HTMLDialogElement).open
    }

    /**
     * ⚠️ THE FILE IS WHAT OPENS IT. No `open` prop beside `media`: two ways of saying the same
     * thing drift, and the pair that drifts here is a viewer showing nothing at full screen.
     *
     * ⚠️ AND IT IS READ AFTER A TICK, NECESSARILY. The element does not exist before the render,
     * so showing it is deferred to the post-flush queue: a bench that reads the state in the same
     * breath as mounting reads it before anything has been asked to open, and would report every
     * dialog in this package as closed.
     */
    it('is closed with nothing to show', async () => {
        const empty = viewer({ media: null })
        const shown = viewer()

        await nextTick()

        expect(isOpen(empty)).toBe(false)
        expect(isOpen(shown)).toBe(true)
    })

    it('shows an image at its own shape', () => {
        const image = viewer().find('img')

        expect(image.attributes('src')).toBe('/media/m1/file')
        expect(image.classes().join(' ')).toContain('object-contain')
    })

    it('plays a video and a sound rather than showing nothing', () => {
        expect(viewer({ media: media('v', { type: 'video' }) }).find('video').exists()).toBe(true)
        expect(viewer({ media: media('a', { type: 'audio' }) }).find('audio').exists()).toBe(true)
    })

    /**
     * ⚠️ THE MIME TYPE DECIDES, NOT THE FAMILY. A PDF is a "document" exactly as a spreadsheet
     * is, and only one of the two is something a browser can draw.
     */
    it('renders a PDF and offers to save a spreadsheet', () => {
        const pdf = viewer({ media: media('p', { type: 'document', mime_type: 'application/pdf' }) })
        const sheet = viewer({
            media: media('s', {
                type: 'document',
                mime_type: 'application/vnd.ms-excel',
            }),
        })

        expect(pdf.find('iframe').exists()).toBe(true)

        expect(sheet.find('iframe').exists()).toBe(false)
        expect(sheet.text()).toContain('cannot be shown here')
        expect(sheet.text()).toContain('Download')
    })

    /**
     * ⚠️ A DOCUMENT IS READ THROUGH THE PACKAGE'S OWN ROUTE, NOT THROUGH THE ADDRESS IN THE
     * RESOURCE. Object storage signs its links with `Content-Disposition: attachment` on every
     * object — measured on a real container on 25/08/2026 — so a frame pointed at one downloads
     * the file instead of showing it, and the viewer opens blank behind a save dialog. Fetching
     * the bytes as a blob is the usual answer and is not available either: the same container
     * sends no `Access-Control-Allow-Origin`, so the request never completes.
     */
    it('reads a document through the route that serves it inline', () => {
        const wrapper = viewer({
            media: media('p', {
                type: 'document',
                mime_type: 'application/pdf',
                url: 'https://storage.example/p.pdf?signature=abc',
            }),
        })

        expect(wrapper.find('iframe').attributes('src')).toBe('/media/p/file')
    })

    /** ⚠️ AND AN IMAGE IS NOT MOVED ONTO THAT ROUTE, because it never needed it: `<img>` fetches
     * its bytes and ignores the header entirely. Serving every picture through PHP to solve a
     * problem pictures do not have would put the whole library through the application. */
    it('leaves a picture on the address the resource gave', () => {
        const wrapper = viewer({
            media: media('i', { type: 'image', url: 'https://storage.example/i.png?signature=abc' }),
        })

        expect(wrapper.find('img').attributes('src')).toBe(
            'https://storage.example/i.png?signature=abc',
        )
    })

    /** ⚠️ AND THE ONE ACT THAT WORKS ON ANY FILE IS RIGHT THERE, rather than sending somebody
     * back to the menu they came from. */
    it('saves the file it could not show', async () => {
        const caught = catchDownloads()
        const sheet = viewer({
            media: media('s', {
                type: 'document',
                mime_type: 'application/vnd.ms-excel',
                download_url: '/media/s/download',
                file_name: 's.xlsx',
            }),
        })

        await sheet.findAll('button').filter((one) => one.text().includes('Download'))[0]
            ?.trigger('click')

        expect(caught).toEqual([{ href: '/media/s/download', name: 's.xlsx' }])
    })

    /**
     * ⚠️ IT SHOWS THE ONE FILE IT WAS ASKED ABOUT, AND OFFERS NO WAY TO THE NEXT. Stepping was
     * there and came out: it only ever covered the page somebody happened to be on, so the
     * arrows stopped at a boundary that means nothing to them. Closing and clicking the next
     * file is one gesture, and it is the one that does what it looks like.
     */
    it('offers no way from one file to another', () => {
        const wrapper = viewer()
        const labels = wrapper
            .findAll('button')
            .map((one) => one.attributes('aria-label'))
            .filter((one): one is string => one !== undefined)

        expect(labels).toEqual(['Close the preview'])
    })

    /**
     * ⚠️ THE BACKDROP IS PART OF THE DIALOG ELEMENT, so a click on it arrives with the dialog as
     * its target — the only way to tell it apart from a click on the photograph. Closing on both
     * is how people lose their place mid-look.
     */
    it('closes on the ground around it and not on what it shows', async () => {
        const wrapper = viewer()

        await wrapper.find('img').trigger('click')

        expect(wrapper.emitted('close')).toBeUndefined()

        await wrapper.find('dialog').trigger('click')

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('closes on the button and on Escape', async () => {
        const closing = viewer()

        await closing
            .findAll('button')
            .filter((one) => one.attributes('aria-label') === 'Close the preview')[0]
            ?.trigger('click')

        expect(closing.emitted('close')).toHaveLength(1)

        const escaping = viewer()

        await escaping.find('dialog').trigger('cancel')

        expect(escaping.emitted('close')).toHaveLength(1)
    })
})

describe('giving one thing a different name', () => {
    const onFile = { kind: 'media' as const, id: 'm1', name: 'Invoice' }
    const onFolder = { kind: 'folder' as const, id: 'f1', name: 'Clients' }

    function renamer(target: MhRenameTarget | null = onFile, api = fakeClient()) {
        const wrapper = mount(MhRenamer, {
            props: { target, client: api },
            attachTo: document.body,
        })

        return { wrapper, api }
    }

    function field(wrapper: ReturnType<typeof mount>) {
        return wrapper.find('input[type="text"]')
    }

    function save(wrapper: ReturnType<typeof mount>) {
        return wrapper.find('button[type="submit"]')
    }

    it('is closed with nothing to rename', async () => {
        const closed = renamer(null).wrapper
        const open = renamer().wrapper

        await nextTick()

        expect((closed.find('dialog').element as HTMLDialogElement).open).toBe(false)
        expect((open.find('dialog').element as HTMLDialogElement).open).toBe(true)
    })

    /**
     * ⚠️ THE FIELD STARTS ON THE CURRENT NAME. Renaming is almost always an edit of what is
     * there — a typo, a date, a suffix — and an empty box makes somebody retype a name they were
     * happy with in order to change one letter of it.
     */
    it('starts on the name the thing already has', () => {
        expect((field(renamer().wrapper).element as HTMLInputElement).value).toBe('Invoice')
    })

    /** ⚠️ AND IT FOLLOWS A CHANGE OF SUBJECT, or the second thing renamed inherits the first
     * one's name. */
    it('follows the thing it is asked about', async () => {
        const { wrapper } = renamer()

        await wrapper.setProps({ target: onFolder })

        expect((field(wrapper).element as HTMLInputElement).value).toBe('Clients')
    })

    /**
     * ⚠️ A FILE AND A FOLDER ARE RENAMED THROUGH DIFFERENT ENDPOINTS, and the kind is carried
     * rather than guessed: telling them apart by looking for a `mime_type` works until a host's
     * folder resource grows one.
     */
    it('renames a file through the file endpoint', async () => {
        const { wrapper, api } = renamer()

        await field(wrapper).setValue('Invoice 2026')
        await save(wrapper).trigger('submit')
        await nextTick()

        expect(api.calls.filter((call) => call.method === 'update')).toEqual([
            { method: 'update', args: ['m1', { name: 'Invoice 2026' }] },
        ])
    })

    it('renames a folder through the folder endpoint', async () => {
        const { wrapper, api } = renamer(onFolder)

        await field(wrapper).setValue('Clients 2026')
        await save(wrapper).trigger('submit')
        await nextTick()

        expect(api.calls.filter((call) => call.method === 'updateFolder')).toEqual([
            { method: 'updateFolder', args: ['f1', { name: 'Clients 2026' }] },
        ])
    })

    /** ⚠️ THE SAME NAME IS NOT A CHANGE, and a name of spaces is not a name. Both are refused
     * here rather than at the server, so nothing is spent on an answer already known. */
    it('asks for nothing when the name has not changed', async () => {
        const { wrapper, api } = renamer()

        await save(wrapper).trigger('submit')
        await nextTick()

        expect(api.calls).toHaveLength(0)
    })

    /**
     * ⚠️ SUBMITTED THROUGH THE FORM, NOT THE BUTTON. The button is disabled on the same condition
     * this refuses, and the test tool declines to fire an event on a disabled control — so a
     * bench that clicks it certifies the attribute and never reaches the guard behind it. The
     * mutation that removed the guard stayed green. A form can be submitted without its button:
     * Enter does it, and so does anything scripted.
     */
    it('refuses a name of spaces', async () => {
        const { wrapper, api } = renamer()

        await field(wrapper).setValue('   ')

        expect(save(wrapper).attributes('disabled')).toBeDefined()

        await wrapper.find('form').trigger('submit')
        await nextTick()

        expect(api.calls).toHaveLength(0)
    })

    it('says so and closes once it worked', async () => {
        const { wrapper } = renamer()

        await field(wrapper).setValue('Invoice 2026')
        await save(wrapper).trigger('submit')
        await nextTick()

        expect(wrapper.emitted('renamed')).toHaveLength(1)
        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    /**
     * ⚠️ A FAILURE KEEPS THE PROMPT OPEN. Closing would take the typed name away with it, and a
     * name refused for being taken already is one somebody wants to edit rather than retype.
     */
    it('stays open and says why when it was refused', async () => {
        const api = fakeClient()
        api.failWith(new MediaHubError(422, null, 'That name is taken.'))

        const { wrapper } = renamer(onFile, api)

        await field(wrapper).setValue('Invoice 2026')
        await save(wrapper).trigger('submit')
        await nextTick()
        await nextTick()

        expect(wrapper.emitted('close')).toBeUndefined()
        expect(wrapper.text()).toContain('That name is taken.')
    })
})

describe('the two surfaces the screen lends', () => {
    /** ⚠️ AN ENTRY WHOSE SURFACE IS MISSING IS NOT OFFERED AT ALL, rather than offered and
     * inert — which is how a screen teaches people to stop reading it. */
    it('offers neither where nothing was lent', () => {
        const list = useMediaActionList(
            fakeClient(),
            () => oneFile,
            undefined,
            () => ({ trashed: false, picking: false }),
        )

        expect(list.available.value.map((one) => one.id)).not.toContain('preview')
        expect(list.available.value.map((one) => one.id)).not.toContain('rename')
    })

    it('hands the viewer the file that was pointed at', async () => {
        const shown: string[] = []
        const list = useMediaActionList(
            fakeClient(),
            () => oneFile,
            undefined,
            () => ({ trashed: false, picking: false }),
            () => ({ preview: (id: string) => shown.push(id) }),
        )

        await list.all.value.find((one) => one.id === 'preview')!.run(oneFile)

        expect(shown).toEqual(['m1'])
    })

    it('hands the prompt the thing that was pointed at', async () => {
        const asked: Selection[] = []
        const list = useMediaActionList(
            fakeClient(),
            () => oneFolder,
            undefined,
            () => ({ trashed: false, picking: false }),
            () => ({ rename: (selection: Selection) => asked.push(selection) }),
        )

        await list.all.value.find((one) => one.id === 'rename')!.run(oneFolder)

        expect(asked).toEqual([oneFolder])
    })
})

/** ⚠️ THE GLYPHS ARE NOT DECORATION HERE: an entry with no drawing sits in a column of drawings
 * and reads as the odd one out. */
describe('the drawings on the entries', () => {
    it('gives every shipped action one', () => {
        const list = useMediaActionList(
            fakeClient(),
            () => oneFile,
            undefined,
            () => ({ trashed: false, picking: false }),
            () => surfaces,
        )

        expect(list.all.value.filter((one) => one.icon === undefined).map((one) => one.id)).toEqual(
            [],
        )
    })

    /** ⚠️ AND A HOST'S OWN ENTRY STILL RENDERS WITHOUT ONE, rather than leaving a hole where an
     * icon would be. */
    it('lets a host add one that has none', () => {
        const theirs = { id: 'publish', label: 'Publish', run: () => Promise.resolve() }
        const list = useMediaActionList(
            fakeClient(),
            () => oneFile,
            () => [theirs],
            () => ({ trashed: false, picking: false }),
            () => surfaces,
        )

        expect(list.available.value.map((one) => one.id)).toContain('publish')
    })
})

/* ⚠️ THE FAKE TIMERS OF A NEIGHBOURING FILE, AND THE STUBBED GLOBALS OF THIS ONE, both outlive
 * the test that installed them unless something puts them back. */
afterEach(() => {
    vi.useRealTimers()
})

/* Kept out of the way of `folder()` being unused otherwise. */
void folder

describe('waiting, drawn on the thing being waited for', () => {
    /**
     * ⚠️ DUPLICATING GAVE NO SIGN AT ALL UNTIL THE COPY APPEARED. On a large file that is several
     * seconds of a menu entry that looks like it did nothing — so it gets clicked again, and the
     * library ends up with three copies of the same photograph.
     *
     * ⚠️ AND THE MARK IS ON THE FILE, NOT OVER THE SCREEN. A veil in the middle of the window
     * blocks a library somebody could still be using, and says nothing about which file it is
     * waiting on. Here, "is it working?" and "on what?" are the same mark.
     */
    it('marks the tile something is being done to', () => {
        const wrapper = mount(MhItemCard, {
            props: { media: media('m1'), busy: true },
            attachTo: document.body,
        })

        expect(wrapper.attributes('aria-busy')).toBe('true')

        /* ⚠️ THE RING, NOT "AN SVG". Every tile already draws one for the kind of file it is, so
         * asking whether a drawing exists answered yes with the overlay removed entirely — the
         * mutation survived and the bench looked thorough. The spinner is the only mark on this
         * screen built from a `<circle>` element rather than paths. */
        expect(wrapper.find('circle').exists()).toBe(true)
    })

    /** ⚠️ ANNOUNCED, NOT ONLY DRAWN. A spinning ring is nothing at all to a screen reader. */
    it('says nothing about a tile at rest', () => {
        const wrapper = mount(MhItemCard, {
            props: { media: media('m1') },
            attachTo: document.body,
        })

        expect(wrapper.attributes('aria-busy')).toBeUndefined()
    })

    /**
     * ⚠️ AND IT TAKES THE MENU AWAY WHILE IT LASTS. The file being copied must not be asked to do
     * a second thing at the same time; the overlay swallows the pointer, and the one control that
     * sits above it goes with it.
     */
    it('offers nothing else on a tile that is busy', () => {
        const wrapper = mount(MhItemCard, {
            props: { media: media('m1'), busy: true },
            attachTo: document.body,
        })

        expect(wrapper.findAll('button')).toHaveLength(0)
    })

    /** ⚠️ ONLY THE ONES NAMED. A batch marks its own files and leaves the rest of the page alone. */
    it('marks only the tiles it was told about', () => {
        const wrapper = mount(MhItemGrid, {
            props: { media: [media('a'), media('b'), media('c')], busy: ['b'] },
            attachTo: document.body,
        })

        expect(
            wrapper.findAll('[role="option"]').map((one) => one.attributes('aria-busy')),
        ).toEqual([undefined, 'true', undefined])
    })

    it('marks a folder the same way, and stops it being opened', () => {
        const wrapper = mount(MhFolderList, {
            props: { folders: [folder('f1'), folder('f2')], busy: ['f1'] },
            attachTo: document.body,
        })

        const tiles = wrapper.findAll('button[aria-busy], button:not([aria-busy])')
        const busy = wrapper.find('button[aria-busy="true"]')

        expect(busy.exists()).toBe(true)
        expect(busy.attributes('disabled')).toBeDefined()
        expect(tiles.length).toBeGreaterThan(1)
    })

    /**
     * ⚠️ THE BATCH BAR HANDS IT UP TOO, and its half was uncovered while the menu's was not — a
     * mutation that made it always report "nothing" stayed green. The two renderers are
     * interchangeable everywhere else in this package; a wait that only one of them reports is
     * exactly the sort of difference nobody documents and everybody meets once.
     */
    it('has the batch bar report what it is working on', async () => {
        const held = deferred<unknown>()
        const slow: MhAction = {
            id: 'slow',
            label: 'Slow',
            available: () => true,
            run: () => held.promise,
        }

        const wrapper = mount(MhSelectionBar, {
            props: { selection: oneFile, client: fakeClient(), actions: [slow] },
            attachTo: document.body,
        })

        await wrapper
            .findAll('[role="toolbar"] button')
            .filter((one) => one.text() === 'Slow')[0]
            ?.trigger('click')
        await nextTick()

        expect(wrapper.emitted('busy')?.[0]).toEqual([oneFile])

        held.resolve(null)
        await nextTick()
        await nextTick()

        expect(wrapper.emitted('busy')?.at(-1)).toEqual([null])
    })

    /**
     * ⚠️ THE RUNNER SAYS WHAT IT IS WORKING ON, NOT MERELY THAT IT IS. A boolean can only be drawn
     * in the middle of the screen; the thing that was asked about can be drawn on itself.
     */
    it('names what is being acted on while it runs, and lets go afterwards', async () => {
        const held = deferred<unknown>()
        const runner = useActionRunner(() => oneFile)

        const running = runner.request({
            id: 'slow',
            label: 'Slow',
            run: () => held.promise,
        })

        expect(runner.busy.value).toEqual(oneFile)

        held.resolve(null)
        await running

        expect(runner.busy.value).toBeNull()
    })

    /**
     * ⚠️ AND IT LETS GO WHEN THE ACT FAILED, TOO. Cleared from the call site rather than in a
     * `finally`, a refusal would leave the tile spinning for ever — over a file nothing is
     * happening to, on a screen that otherwise looks fine.
     */
    it('lets go when the act was refused', async () => {
        const runner = useActionRunner(() => oneFile)

        /* ⚠️ THE MARK IS SET BEFORE ANYTHING IS AWAITED, which is what makes it readable here at
         * all: the refusal has not been handled yet on this line. */
        const running = runner.request({
            id: 'slow',
            label: 'Slow',
            run: () => Promise.reject(new MediaHubError(500, null, 'No.')),
        })

        expect(runner.busy.value).toEqual(oneFile)

        await running

        expect(runner.busy.value).toBeNull()
        expect(runner.error.value).not.toBeNull()
    })
})
