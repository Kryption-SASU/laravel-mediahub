# Getting the browser side into your application

Three ways, and which one you want depends on a single question: **does your application already
build JavaScript?**

| | For | You do | You get |
|---|---|---|---|
| **Bundled** | an application with Vue 3 and a bundler | point your bundler at the sources | the TypeScript sources, Vue as a peer dependency |
| **Standalone** | a Laravel application with no build at all | `composer require`, then publish the assets | one `.js` and one `.css`, Vue included |
| **Headless** | anything that renders its own screens | nothing | the JSON API, and the typed client if you want it |

---

## Bundled

Your build compiles our sources, so it decides the target, the minification and the
tree-shaking, and nothing is compiled twice.

```js
// vite.config.js
resolve: {
    alias: {
        '@mediahub/components': fileURLToPath(
            new URL('./vendor/kryption/laravel-mediahub/resources/js/components', import.meta.url),
        ),
    },
},
```

⚠️ **Tailwind v4 does not scan dependencies.** Add one line, or every class in the theme is
removed from your build — which looks like a broken package rather than a missing directive:

```css
@source "../vendor/kryption/laravel-mediahub/resources/js";
```

---

## Standalone

For a Laravel application with no npm, no Vite and no Vue. The rule this mode exists to keep is
that **`composer require` should be enough to get a working screen.**

```bash
composer require kryption/laravel-mediahub
php artisan vendor:publish --tag=mediahub-assets
```

Vue is bundled in, and paid for only on the pages that carry a library.

### ⚠️ The compiled files exist on tagged versions only

This is the part worth reading before opening an issue.

**The bundle is built when a version is tagged, and committed with that tag.** It is not built on
every commit, and `main` does not carry a current one.

That means:

```bash
# Works: a tagged release carries its bundle.
composer require kryption/laravel-mediahub:^1.0

# Does NOT give you a standalone screen: no bundle is built for a moving branch.
composer require kryption/laravel-mediahub:dev-main
```

Following `dev-main` is supported for the **bundled** and **headless** modes, which compile or
ignore the sources. It is not supported for standalone, and the failure is silent in the worst
way — the routes answer, the API works, and the page renders nothing.

### Why it is done this way

Committing build output contradicts an otherwise sound rule, and the contradiction is real rather
than something to argue away. Three things decided it:

- **A Composer package ships what is in the repository.** There is no install step that could
  build anything: if the file is not committed, an application with no npm can never obtain it.
  Either the artefact is versioned, or the standalone mode does not exist.
- **A tag is a fixed point; a branch is not.** A bundle committed to a moving branch is stale
  from the next commit onwards, and stale in a way nothing reports — the screen simply behaves
  like an older version of itself. Tying it to a tag makes "the bundle matches the sources" a
  property of something that does not move.
- **The pipeline checks the claim rather than trusting it.** On a tag, the bundle is rebuilt from
  the sources and compared with the committed one; a difference fails the release. That check is
  the only thing that makes a versioned artefact honest, and without it this mode would not be
  worth having.

---

## Headless

Nothing to install on the browser side. The package's routes answer JSON, and
[the typed client](browser.md#the-client) is available on its own if you want the types without
any of the rendering.
