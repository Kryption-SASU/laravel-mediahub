# The browser side

Three layers, and the components are being built from the ground up.

- **Layer 1 — the core.** A typed client for the HTTP API and an upload queue. Plain TypeScript,
  no framework. It exists so an Angular, Svelte or vanilla application can use this package
  without pulling Vue into its bundle, and a test reads the sources on every run to keep it that
  way.
- **Layer 2 — the composables.** Vue 3 state and operations: browsing, selection, uploading,
  actions, quota, picking. Nothing renders.
- **Layer 3 — the components.** Vue 3 with **frozen markup** and an appearance settable entirely
  from the outside. So far: the provider, and the primitives every screen is made of.

Layer 2 is written so that building an entirely different interface is a day's work, and there is
[a test that says so](../resources/js/vue/acceptance.test.ts). That is the supported way to get a
screen we did not write — not patching the components below.

## Why the components cannot be published and edited

⚠️ **The markup is the contract.** A view that can be forked is a view that gets forked, and from
that day the package cannot change anything without breaking the copy. That is not a hypothesis;
it is the story that made this package necessary.

The trade is that **everything about the appearance is reachable from the outside**, with no
recompilation and without touching a line of the package. Three levers, in the order you will
need them.

### Lever 1 — the tokens

Nine custom properties cover the ordinary case. One line at the host, no build at all:

```css
@import "../vendor/kryption/laravel-mediahub/resources/css/mediahub.css";

:root {
    --mh-color-accent: var(--brand-primary);
    --mh-radius: 0.75rem;
}
```

Dark mode is a set of tokens, not a second stylesheet. It follows `prefers-color-scheme`, and
`data-mh-theme="dark"` or `"light"` on the root overrides it — an application with its own switch
must be obeyed, otherwise this library stays bright inside a dark page and reads as something that
failed to load.

⚠️ **No preflight is imported.** Resetting your styles from a dependency would be a fault: you
installed a media library, not a new baseline for your application.

⚠️ **Tailwind v4 does not scan dependencies.** Consuming the sources, add one line, or every class
in the theme is removed from your build — which looks like a broken package rather than a missing
directive:

```css
@source "../vendor/kryption/laravel-mediahub/resources/js";
```

### Lever 2 — the class table

Each component reads its classes from a table, and each place in it has two halves:

```ts
thumbnail: {
    root: { layout: 'relative block shrink-0 overflow-hidden', class: 'rounded-md bg-…' },
}
```

`layout` is structure and belongs to the markup contract. `class` is the skin, and **yours
replaces ours outright** — never merged, so there is never a question of which utility wins. An
empty string removes the skin entirely.

```ts
app.use(createMediaHub({ client, theme: { thumbnail: { root: { class: 'rounded-none' } } } }))
```

The nearest word wins: **default, then your theme, then the `ui` prop** on a single component.

```vue
<MhThumbnail :media="media" :ui="{ root: { class: 'ring-2 ring-brand' } }" />
```

⚠️ **No class is ever written into a template**, and [a test reads the sources to keep it that
way](../resources/js/components/no-hardcoded-classes.test.ts). One class typed in during a busy
afternoon is a piece of appearance nobody can reach — and the only remaining option would be to
fork the component.

### Lever 3 — the slots

A small number of named, documented slots. They **inject content; they do not restructure**.
Adding one is a minor version, removing one is a major.

## Getting the components

```ts
import { createMediaHub, MhThumbnail } from '@mediahub/components'

app.use(createMediaHub({ client }))
```

Or, for one screen only — or for two libraries side by side:

```vue
<MhProvider :client="client" :theme="theme">
    <!-- … -->
</MhProvider>
```

`MhProvider` renders **no element of its own**: a wrapper `<div>` would change the layout of
whatever it is dropped into. ⚠️ The client is taken once, at mount — swapping it afterwards has no
effect, so key the provider on your tenant if it changes.

The theme, on the other hand, is reactive: `createMediaHub(...).setTheme(…)` changes the skin of
everything already on screen, which is what a runtime dark mode needs.

## Media in a form

These two are what a host uses every day, and they are built to be used without thinking about
them.

```vue
<MhMediaInput v-model="form.avatar_id" name="avatar_id" :media="post.avatar" :types="['image']" />
<MhMediaGallery v-model="form.gallery_ids" name="gallery_ids[]" :media="post.gallery" />
```

**The model carries the identifier, not the object.** That is what a form posts and what you
store; modelling it as the whole media would make every screen unwrap it before saving, and the
first one that forgets writes a JSON blob into a foreign key column. The object is available too,
through `@update:media`, and can be handed in through `:media` so the field shows a preview
without fetching anything.

**A hidden field carries the value**, so an ordinary Blade form submits with no JavaScript of your
own. ⚠️ It stays in the payload when empty: a field that vanishes once cleared leaves the server
unable to tell "unset it" from "this form never had it".

⚠️ **Give the gallery a name with brackets** — `gallery_ids[]` — or a classic form keeps only the
last value, and six pictures save as one.

**The order of a gallery is its value.** Reordering is done with buttons rather than dragging, and
that is deliberate for now: drag and drop cannot be operated from a keyboard, is awkward on a
touch screen, and shipping it first would mean shipping a gallery that a portion of users simply
cannot reorder. Dragging can be added on top of this later; the reverse could not.

## Choosing a file

```vue
<MhMediaPicker ref="picker" />
```

```ts
const [cover] = await picker.value.pick({ types: ['image'] })
```

⚠️ **A dismissal resolves with an empty list; it does not reject.** Closing a picker is the most
ordinary thing anyone does with one, and rejecting would hand an unhandled rejection to whoever
forgot a `try` around a click on "cancel". By the same reasoning, confirming is refused while
nothing is chosen — an empty answer to a deliberate confirmation is indistinguishable, to the
caller, from a dismissal.

It fetches when it opens rather than when it mounts, so a picker sitting beside a form costs
nothing on the pages nobody opens it from.

## Acting on a selection

The toolbar and the context menu render **the same list**, from `useMediaActionList`, and a test
compares what the two produce for the same selection. Two lists kept by hand diverge at the first
addition: somebody adds an action to the bar, forgets the menu, and nothing turns red — the screen
still works, it simply offers less depending on where you clicked.

```vue
<MhSelectionBar :selection="selection.asSelection()" @clear="selection.clear()" />
<MhContextMenu v-model:open="menuOpen" :selection="selection.asSelection()" :x="x" :y="y" />
```

### What ships

| `id` | Offered when |
|---|---|
| `preview` | one file, and the screen lent a viewer |
| `link` | one file — copies the address the server gives for it |
| `rename` | one file **or one folder** |
| `duplicate` | one file — the copy lands beside the original, named "… (copy)" |
| `download` | one file, as itself |
| `archive` | a folder, or more than one thing — one ZIP of the lot |
| `trash` · `restore` · `purge` | a non-empty selection; the last two in the trash only |

Two rules run through that table:

- ⚠️ **One file goes as itself, anything else goes as a ZIP.** `download` and `archive` are
  complementary rather than alternative, so on any selection outside the trash exactly one of
  them is offered and nobody is left having ticked something they cannot take away.
- ⚠️ **Everything that acts on a single thing is offered where one thing is being pointed at,
  and not while a batch is being assembled.** A file cannot be renamed to two names, and
  duplicating four files is four acts. Reading "one thing is ticked" instead would show `rename`
  for as long as the batch happened to hold one file and take it away at the second.

`preview` and `rename` are a **surface** rather than a request, and a table of data cannot own
one. `MhMediaLibrary` lends them through `MhActionSurfaces`; a host rendering `MhContextMenu` on
a screen of its own is offered neither, rather than two entries that do nothing.

⚠️ **Nothing ever reads an archive into the page.** `requestArchive` submits a form into a hidden
frame and lets the browser save the answer: a `fetch()` followed by a `blob()` would put a
streamed ZIP back into the tab's memory and fail on exactly the archives that most needed
streaming. Same origin means the frame can be read, so a refusal — an archive beyond what the
server can finish — comes back and is raised like any other failure instead of filling a blank
tab with JSON.

⚠️ **A download fires no event, so success is heard through a cookie.** The browser cancels the
frame's navigation and saves the file: `load` never comes, and an empty frame is no evidence of
anything — a frame with no source loads `about:blank` on its own before the form is even
submitted. So the response announces itself in its headers, with a cookie
(`mediahub_archive_started`) that reaches the jar the moment the answer begins, download or not.
The page watches for it, clears it, and stops showing the selection as busy.

Shipped without it, the spinner stayed on the selection for ever while the ZIP sat finished in
the downloads folder.

⚠️ **But "the answer has begun" is not "the archive is finished."** The cookie arrives within a
second; the download then runs for as long as it runs. Read as completion, the overlay came off
after a second while the ZIP was still coming down — reported from a real screen, and the reason
there is a second mechanism below.

| | |
|---|---|
| the frame loads a JSON document | a refusal — raised, with the server's `reason` |
| the count says `done` | the archive has been written; **this is what ends the wait** |
| the cookie appears, and nothing is counting | the answer has begun — all anyone will ever know on a host whose count cannot be reached |
| nothing heard for two minutes | the wait ends anyway and says nothing: a page left spinning is the fault this exists to prevent |

⚠️ **Once the count has spoken, the cookie stops deciding.** The record it is read from expires
after fifteen minutes; a download that outlives it would find the mark still set and end the wait
on it — the same fault by the back door, on exactly the long archives where it hurts.

## How far the archive has got

```
GET {prefix}/archive/progress/{ticket}
```

```json
{ "data": { "known": true, "total": 696254464, "written": 412876800, "done": false } }
```

The page makes a ticket, sends it with the selection, and asks a **second** request about it —
the one writing the ZIP is busy, and will not be free to answer until nobody needs telling. The
bytes are counted **inside** each file, by a stream filter, rather than between them: an archive
is often one large video and four small images, and counting per entry holds the bar at 3% for
two minutes and then jumps to 100%.

| | |
|---|---|
| `known: false` | never heard of it — asked too early, or the record has expired. **Not an error**, and not a 404: a page told "no such archive" gives up on a download that is about to start |
| `total` | the weight going **in**. A ZIP's own size cannot be known before it is written — that is what makes it streamable at all |
| `done` | said outright, never inferred from the numbers meeting: a file that could not be read leaves the count short for ever |

⚠️ **This is the server's side of the transfer, not the browser's.** Between the last byte written
and the last byte received there are network buffers — a few hundred milliseconds, more on a slow
line. Close, and not the same thing.

⚠️ **It needs a cache two requests can meet in — and Laravel offers several that are not.**

```php
'archives' => [
    /** Null means the application's own default store. */
    'progress_store' => null,
],
```

| store | a second request can read it |
|---|---|
| `redis`, `memcached`, `database`, `dynamodb` | yes, across machines too |
| `file` | yes on one machine; across servers only if the directory is shared |
| `apc`, `octane` | yes on one machine, never across servers |
| `array`, `null` | **no** — they live and die inside one request |

On `array` or `null` the answer is always `known: false`, so no bar ever appears and the page
falls back to the cookie: the wait ends when the answer begins. Nothing breaks and archives still
download — which is exactly what makes the missing percentage hard to attribute to a cache
setting three files away. **The health report says so out loud**, names the store it found, and
points at the setting that fixes it.

⚠️ **The store is named from its class, not from your configuration.** Reading back your own
setting teaches nobody anything; "your cache is `array`" when you configured `redis` does.

⚠️ **The count is written at most four times a second, and at most once per megabyte.** Reading
at 300 MB/s, the byte rule alone would ask the cache to write three hundred times a second —
three hundred statements a second on a database-backed store, for a figure no eye can follow at
that rate.

⚠️ **The ticket is patterned at the route and checked again before it touches a cache key**
(`[A-Za-z0-9]{8,64}`). It arrives in a request body and ends up concatenated into that key.

⚠️ **And it is a scalar field, not a list.** The form that carries the selection used to append
`[]` to every field it wrote, which is right for `media` and wrong for everything beside it:
`ticket[]` reaches the server as a one-element array, which is not a string, which is silently no
ticket at all — and then no percentage, for ever, on a screen that looks exactly the same. The
brackets are now part of the name the client builds.

⚠️ **Only the cookie's presence is read, never its value**, so it does not matter whether the
host encrypts its outgoing cookies. And **the name is fixed on both sides** — a PHP test holds
the constant and the TypeScript together, because nothing else could: neither suite can run the
other's code, so a rename would leave both green and the spinner stuck again.

⚠️ **What is being reported is the wait for the server to begin, not the download.** Once the
browser has the attachment it shows its own progress and tells the page nothing more; a spinner
claiming to track the transfer would be guessing from the first byte to the last.

⚠️ **"Rebuild the thumbnail" is offered only where the server says it could draw one.** That is
not a property of the type: the same `video/mp4` is drawable on a machine with ffmpeg and not on
one without, and the browser has no way of working it out. The payload therefore carries
`can_draw` per file, and the menu obeys it — written from the type alone, the entry appears on
half the installations that exist and earns a refusal for its trouble.

It needs the file itself, not its identifier, so it arrives through `MhActionContext.subject`. ⚠️
A screen that renders the menu from identifiers alone loses that one entry, not the menu.

## Two sizes, and which screen asks for which

A grid wants a small picture; a panel showing one file wants a large one. ⚠️ **The difference is
visible**: a detail panel used to blow the 256-pixel thumbnail up to fill itself, which reads as a
bad picture rather than as the wrong size being asked for.

```php
'definitions' => [
    'thumb'   => ['width' => 256,  'height' => 256,  'fit' => 'cover'],
    'preview' => ['width' => 1024, 'height' => 1024, 'fit' => 'contain',
                  'types' => ['video', 'document']],
],
'thumbnail' => 'thumb',    // which definition the grid asks for
'preview'   => 'preview',  // which one a panel asks for
```

⚠️ **`types` is what keeps the large one from costing everybody.** Without it every photograph in
the library grows a second large derivative that no screen asks for — double the conversion work
and double the storage, to serve nothing. An image already has an original worth showing; a video
and a PDF do not, which is the whole reason the large one exists.

⚠️ **An absent or empty `types` means "all of them"** — every definition written before this
existed says exactly that, and must go on meaning it.

⚠️ **And the payload names each role rather than answering "the first ready derivative".** With
one definition those were the same thing; with two it is a draw, and a grid could be handed the
full-size preview on some rows and not others — every tile quietly weighing four times what it
should, on a screen showing twenty-four of them.

`MhThumbnail` takes `prefer="preview"` where the large one is wanted, and falls back on its own:
preview, then thumbnail, then the original for an image.

## A tile says what it is

⚠️ **A video's thumbnail is a photograph, and that is the problem.** Since a frame is drawn from
it, nothing on the tile says it is a video any more — and the same goes for the first page of a
PDF, which looks like a picture of a sheet of paper. A badge in the corner puts the nature back.

| | |
|---|---|
| a drawn thumbnail on anything but an image | badged with the type's own glyph |
| an image | **no badge** — there the thumbnail *is* the file, and labelling a photograph as a photograph on every tile is noise |
| no thumbnail at all | no badge — the tile already draws the type's icon full size |

⚠️ **The badge is announced, not decorative.** It carries the one thing nothing else on the tile
says: a video and a photograph both offer a file name and a picture. Hidden from assistive
technology it would restore the nature for some people and not others.

Actions are **data**, so you can add your own — and yours replace ours by `id` rather than
appearing beside them:

```ts
const actions: MhAction[] = [
    {
        id: 'publish',
        label: 'Publish',
        /** Optional: SVG path data on a 24 grid, drawn beside the label in both renderers. */
        icon: ['M12 4v12', 'M8 12l4 4 4-4'],
        available: (selection) => (selection.media?.length ?? 0) > 0,
        confirm: { title: 'Publish these files?' },
        run: (selection) => publish(selection),
    },
]
```

⚠️ **`confirm` means "ask first", and the asking lives in one place.** A context menu that
deleted without asking, beside a bar that asks, is a difference nobody documents and everybody
discovers once. The selection is read **when the action runs**, not when it was requested:
between the click and the answer somebody can tick another file, and acting on the older
selection is exactly what a confirmation is supposed to prevent.

## Uploading, on screen

```vue
<MhUploadButton @files="upload.add($event, { folder })" />

<MhDropzone @files="upload.add($event, { folder })">
    <MhFolderList :folders="folders" @open="open" />
    <MhItemGrid :media="media" />
</MhDropzone>

<MhUploadQueue
    :items="upload.items.value"
    @abort="upload.abort($event)"
    @retry="upload.retry($event)"
    @clear="upload.clearFinished()"
/>
```

⚠️ **There are two routes, and one of them is not optional.** Dragging cannot be done from a
keyboard, is awkward with a screen reader and is impossible on most touch devices — for a portion
of your users a file picker is the only way in. `MhUploadButton` is that picker: a real, labelled
`<input type="file">` whose label is the button, kept in the accessibility tree and in the tab
order rather than hidden behind a click handler on a `display: none` element. **A screen that
renders `MhDropzone` alone has to render one too**, or adding a file becomes something only a
mouse can do.

⚠️ **And the drop zone wraps the listing rather than sitting above it.** A dashed rectangle
parked over the grid accepts a drop on its own few hundred pixels and nowhere else — so a file
let go over the files, which is where the hand goes, is opened by the browser, taking the page
and whatever was in it. Given children, the zone becomes the area somebody is actually looking
at, and shows nothing at all until there is something to catch.

Each file gets its own row and its own progress. One request per file means one can be refused —
too large, a type nobody allows — while the rest land; a single bar for the batch would report
the whole thing as failed, and somebody would upload nineteen files again to recover one. An
upload whose total the browser cannot compute shows as **indeterminate rather than at zero**,
because a bar pinned at the left end says "nothing has happened" while bytes are going out.

## The quota, and the details

`MhQuotaMeter` draws a native `<meter>` where there is a limit, and **nothing at all where there
is none** — zero would read as "empty" and a hundred as "full", while the truth is that the
question does not apply. Sizes are spelled out: "1073741824" is a number people convert in their
head every time they look at it.

`MhDetailsPanel` shows one file and lets two things about it be changed: its name, and its
**alternative text**. The second is a field at the same size as the first, deliberately — this is
the only place in a media library where anybody can write it, and a library that never asks
produces a site where every picture is silent.

⚠️ **Only what changed is sent.** Attaching the properties to every rename would overwrite
an alternative text somebody else edited in the meantime, and the loss would be invisible:
nothing failed, the field simply went back to what that screen happened to be holding.

## The whole screen

```vue
<MhProvider :client="client">
    <MhMediaLibrary :actions="myActions" @open="preview" />
</MhProvider>
```

That is the entire integration. The component is **wiring and nothing else**: every piece of
state it shows belongs to a composable, and it makes no request of its own. Had the assembly
needed to reach past a layer, the layer would have been cut wrong — which is why this screen was
built last rather than first.

It is also **the component most likely to be replaced**, and that is a supported outcome. A host
who wants a different screen writes their own version of this one file, on the same composables,
and keeps every component below it. The wiring is deliberately thin so it can be read as an
example.

```vue
<MhMediaLibrary :diagnostics="serverSaysDiagnosticsAreOn" />
```

Behaviours it owns, because nothing below it could:

- ⚠️ **The wait is drawn on the thing being waited for.** The menu and the bar know an act is
  running and know nothing about where on screen the files it names are; this screen knows the
  opposite. Half of each is why duplicating a large file used to give no sign at all until the
  copy appeared — so it got clicked again.
- ⚠️ **The selection is dropped when the folder changes.** Carrying it across means a batch
  action runs on files nobody can see any more — and the confirmation names a count rather than
  the files, so nothing on screen would give it away.
- ⚠️ **An action refreshes the listing and the quota.** Deleting a gigabyte and leaving the
  gauge where it was tells somebody the deletion did not work, and they do it again.

The context menu is offered only where there is something to act on: opening an empty box in
place of the browser's own menu takes something away and gives nothing back.

### The health report

`:diagnostics` shows one discreet button in the toolbar, and pressing it runs the report — the
package comparing what its configuration promises against what the machine will actually do.

⚠️ **The flag is the server's, passed through rather than guessed.** `GET diagnostics` is not
registered unless `mediahub.diagnostics.enabled` is true, so a button drawn on a hunch is one
that answers 404 — and it would be offered to everybody who can look at a photograph. Read the
setting on the server and hand it to the screen; two booleans kept in step is a button that
survives the closing of the door.

⚠️ **It is asked for on the click, never on the mount.** Reading `php.ini` and probing extensions
every time somebody opens a media library is work nobody asked for.

The sentences come from the server whole. They name directives, measured values and a value to
set; a screen that turned a key into a sentence would be inventing the numbers on it.

### Folders

⚠️ **A list of children, not a tree — and that is a limit of the server rather than a
preference.** Browsing answers with the contents of one folder, so a tree would mean a request per
branch, or an endpoint that does not exist yet. A component pretending to be a tree would either
be slow in a way nobody could explain, or show a shape that is not the real one. A real tree is a
later addition, and it needs the server first.

## The keyboard

The grid is a listbox with **one tab stop**, and the arrows move inside it. Twenty-four items each
taking a stop would mean pressing Tab twenty-four times to get past a picker — every screen that
embedded one would become slower to leave than to use.

| | |
|---|---|
| ← → | previous, next |
| ↑ ↓ | by a row, if the grid was told its `columns`; otherwise by one |
| Home / End | first, last |
| Space | choose |
| Enter | open — in a picker, choose and close |
| Escape | dismiss, or close the context menu |

Dialogs are native `<dialog>` elements, so the focus trap, Escape and the inertness of everything
behind them come from the browser rather than from code that has to be maintained.

## Getting hold of it

The package is not published to npm. Its browser sources ship inside the Composer package, and
you point your bundler at them:

```js
// vite.config.js
import { fileURLToPath } from 'node:url'

export default {
    resolve: {
        alias: {
            '@mediahub/client': fileURLToPath(
                new URL('./vendor/kryption/laravel-mediahub/resources/js/client', import.meta.url),
            ),
            '@mediahub/vue': fileURLToPath(
                new URL('./vendor/kryption/laravel-mediahub/resources/js/vue', import.meta.url),
            ),
        },
    },
}
```

You are compiling TypeScript sources, not a built bundle. That is deliberate: your build decides
the target, the minification and the tree-shaking, and nothing here is compiled twice.

## The client

```ts
import { createMediaHubClient } from '@mediahub/client'

const media = createMediaHubClient({
    baseUrl: '/media',
    csrfToken: () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
})

const page = await media.browse({ search: 'invoice', types: ['image'], page: 2 })
```

`csrfToken` is accepted as a function on purpose: the token is re-read on every call, so a session
that rotates it — or a page that replaces the meta tag after a login — does not start failing
silently on the next write.

Every refusal arrives as a `MediaHubError`:

```ts
import { MediaHubError } from '@mediahub/client'

try {
    await media.trash({ media: [id] })
} catch (error) {
    if (error instanceof MediaHubError && error.reason === 'item_not_found') {
        // …
    }
}
```

⚠️ **Branch on `reason`, never on `message`.** The key is stable and never translated; the
sentence is a courtesy and changes with the wording and the locale. Framework validation failures
carry no `reason` at all — they answer `{message, errors}` — so `error.invalid` and
`error.validation` are what to read there.

## The upload queue

```ts
import { createUploadQueue } from '@mediahub/client'

const queue = createUploadQueue(media, { concurrency: 3 })

const stop = queue.subscribe(() => render(queue.items))

queue.enqueue(files, { folder: currentFolder })
```

The queue takes the client rather than a URL and a set of headers: the address, the CSRF token and
the credentials are already its business, and duplicating them here would be a second place to
forget one. `subscribe` returns its own unsubscribe — call it when the screen goes away.

The transport defaults to `XMLHttpRequest` rather than `fetch`, and not out of nostalgia: `fetch`
reports nothing about what goes *up*. A queue built on it shows a spinner and no percentage, on
exactly the files where a percentage is what makes the wait bearable. Pass `transport` yourself
where there is no `XMLHttpRequest` — server-side rendering, a worker, a test.

One request per file, so a rejected file does not take a batch down with it. A `201` that carries
`errors` is a failure for the files it names, not a success for all of them.

## The composables

Provide the client once, near the root:

```ts
import { provideMediaHub } from '@mediahub/vue'

provideMediaHub(media)
```

Then, in any component:

```ts
import { useMediaBrowser, useSelection, useMediaActions } from '@mediahub/vue'

const browser = useMediaBrowser()
const selection = useSelection()
const actions = useMediaActions()

await browser.open(folder)
selection.toggle('media', id)
await actions.trash(selection.asSelection())
await browser.refresh()
```

| | |
|---|---|
| `useMediaBrowser` | the page, the folder, breadcrumbs, search, sort, paging |
| `useSelection` | what is ticked, across both kinds |
| `useMediaActions` | rename, move, copy, annotate, trash, restore, purge |
| `useFolders` | create, rename, move |
| `useUpload` | the queue as reactive state |
| `useQuota` | what is used and what is left |
| `useMediaPicker` | open, choose, and a promise that settles either way |

Each of them takes an explicit client as an argument if you would rather not provide one — the
injection is a convenience, not a requirement.

Two behaviours worth knowing, because both are invisible until they bite:

- **The browser keeps the answer to the last question, not the last answer.** Type quickly and the
  response for `inv` can land after the response for `invoice`; the older one is dropped.
- **The picker always settles.** Cancelling resolves with an empty list rather than rejecting, and
  opening a second picker releases the first — a promise that never settles is a screen that waits
  forever, and the caller has no way to find out.

## Words

⚠️ **The markup is frozen, so translating is the only way to change a label.** That makes the
catalogue part of the contract rather than a convenience: a key that does not exist is a word
nobody can change, and the only remaining move would be to fork the component — which is exactly
what the frozen markup exists to prevent.

Two languages ship complete: `en` and `fr`.

```ts
app.use(createMediaHub({ client, locale: 'fr' }))
```

```vue
<MhProvider :client="client" locale="fr">
```

Both are reactive: `createMediaHub(...).setLocale('en')` changes every word on screen, which is
what a language switcher needs.

### Using your own engine

If the application already runs `vue-i18n`, or anything else, plug it in and keep one catalogue
for the whole product:

```ts
app.use(createMediaHub({ client, text: (key, replace, count) => i18n.t(key, replace, count) }))
```

The catalogue is exported as plain data, so a headless host — or one rendering from something
other than Vue — can consume it directly:

```ts
import { MH_LOCALES } from '@mediahub/components'

const french = MH_LOCALES.fr.messages
```

### Changing one word

Every label is also a prop, and the prop wins. That is the exception, not the route:

```vue
<MhMediaInput v-model="id" choose-label="Pick a portrait" />
```

Use it for a one-off. For anything you would write more than once, translate the key instead —
otherwise the wording lives in forty templates and diverges between screens.

### Counting

⚠️ **Zero does not take the same form in every language.** English says "0 files", French says
« 0 fichier ». The rule belongs to the language, not to the message, so each locale carries its
own — and a single shared rule would be wrong in one of them on every screen that counts
anything.

⚠️ **A key that does not exist comes back as itself.** A blank button says nothing and looks
like a rendering fault; `picker.choose` on a button names exactly what is missing, which is what
somebody adding a language needs to read.


## Keeping the types honest

A TypeScript interface is a claim about another program, and no TypeScript test can check it. Both
sides stay green while they drift: the server renames a key, the browser keeps reading the old one,
and `undefined` travels quietly until it reaches a screen.

So the fixtures under `tests/Fixtures/contract/` are **written by the PHP suite from real
responses** and committed. `resources/js/client/contract.test.ts` assigns them to the declared
types — without a cast — and feeds them through the real client.

- A renamed or retyped key fails `npm run types`, by name, at compile time.
- `ContractFixturesTest` compares the *shape* of every live response with the committed file, so
  the server cannot change one without turning the PHP suite red first.

After a deliberate change:

```bash
MEDIAHUB_WRITE_CONTRACT=1 vendor/bin/phpunit --filter ContractFixturesTest
```

⚠️ **Then read the diff.** Regenerating without looking turns the guard into a rubber stamp.

## Testing

```bash
npm install
npm run types
npm test
npm run test:coverage
```

Coverage floor: **85%**, declared in `vitest.config.ts` so that a local run and the pipeline hold
you to the same number. The environment is `node`, deliberately: nothing in layers 1 and 2 touches
the DOM, and a `jsdom` here would let a DOM dependency slip into code meant for consumers who have
no document.
