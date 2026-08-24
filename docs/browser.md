# The browser side

Two layers, and no components yet.

- **Layer 1 — the core.** A typed client for the HTTP API and an upload queue. Plain TypeScript,
  no framework. It exists so an Angular, Svelte or vanilla application can use this package
  without pulling Vue into its bundle, and a test reads the sources on every run to keep it that
  way.
- **Layer 2 — the composables.** Vue 3 state and operations: browsing, selection, uploading,
  actions, quota, picking. Nothing renders.

Components come next. Until then, the browser side is a foundation you build your own interface
on — which is the point: layer 2 is written so that writing an entirely different interface is a
day's work, and there is [a test that says so](../resources/js/vue/acceptance.test.ts).

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
