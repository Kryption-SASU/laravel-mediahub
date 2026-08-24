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

### Cutting a release

The bundle is ignored on branches and force-added at the tag. `main` therefore never carries
build output, and the tag carries a complete, installable tree.

```bash
git checkout -b release-vX.Y.Z main
npm run build
git add -f dist
git commit -m 'Release vX.Y.Z'
git tag -a vX.Y.Z -m 'vX.Y.Z'
git push origin vX.Y.Z          # the tag only — the branch is never pushed
```

⚠️ **Push the tag, not the branch.** `main` is protected and refuses a direct push anyway, but
the reason is not the protection: a release commit on a branch would put build output on
something that keeps moving, and the artefact would be stale from the next commit onwards.

The release job then rebuilds from the sources of that tag and compares byte for byte. If it
fails, the artefact is behind its code: rebuild, amend, and move the tag.

#### What was measured

The check was cut against itself before being trusted, because a job that has only ever passed is
indistinguishable from one that cannot fail.

- A tag whose bundle was built from its own sources: **passed**.
- A tag where a comment changed and the bundle was not rebuilt: **passed** — and correctly.
  Comments do not survive minification, so the committed artefact really was current. The first
  attempt at proving the guard was the flawed one, not the guard.
- A tag where a default address changed and the bundle was not rebuilt: **failed**, naming the
  files and printing the difference.

⚠️ **That middle case is worth keeping in mind.** The job compares build output, not sources,
so it answers "is this artefact what these sources produce" and not "was it built from this
commit". Those differ only where a change produces identical output — which is precisely when the
distinction does not matter.


### The two published files are not the same kind of thing

`vendor:publish --tag=mediahub-assets` copies two files into `public/vendor/mediahub`. They look
alike sitting next to each other, and they are not.

| | |
|---|---|
| `mediahub.css` | **a default.** Replace it, extend it, or override its tokens. |
| `mediahub.js` | **the package.** Copied so it can be served, never edited. |

⚠️ **Editing the published JavaScript is the fork this package exists to prevent.** It is
erased by the next `vendor:publish --force`, and until then the application runs a version that
no longer matches the package while still reporting its version number — so a bug report against
it cannot be answered, by us or by anyone. If a component does not do what you need, write your
own screen on [the composables](browser.md#the-composables); that is a supported route, and it
survives every upgrade.

### Changing the appearance without a build

**Tokens work here, with no build at all.** Load our stylesheet, then one line of your own after
it:

```html
<link rel="stylesheet" href="/vendor/mediahub/mediahub.css">
<link rel="stylesheet" href="/css/app.css">
```

```css
/* app.css */
:root {
    --mh-color-accent: var(--brand-primary);
    --mh-radius: 0.75rem;
}
```

Our stylesheet ships **without preflight**, so it resets nothing of yours and the two can sit
side by side. Colours, radii and dark mode are all tokens, which is why they are the answer for
this mode.

⚠️ **The class table is not, and this is the one real limit of standalone.** Replacing
`rounded-md` with `rounded-none` through a theme names a class that **is not in our compiled
stylesheet**: Tailwind compiled only what our own sources use. The component will carry a class
name that matches nothing, and nothing will report it — the corner simply stays round.

If you need that lever, you have two honest options, and neither is rebuilding this package:

- write the classes you name into your own stylesheet, or
- use the **bundled** mode, where your Tailwind build compiles them (add the `@source` line).


---

## Headless

Nothing to install on the browser side. The package's routes answer JSON, and
[the typed client](browser.md#the-client) is available on its own if you want the types without
any of the rendering.
