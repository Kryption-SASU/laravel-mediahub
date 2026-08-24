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
| Escape | dismiss |

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

## Uploading

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
