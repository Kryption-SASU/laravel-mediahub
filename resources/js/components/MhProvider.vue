<script setup lang="ts">
import type { MediaHubClient } from '../client'
import { createTranslator, provideMediaText } from '../i18n/context'
import type { MhTranslator } from '../i18n/context'
import { provideMediaTheme } from '../theme/context'
import type { MhThemeOverride } from '../theme/types'
import { provideMediaHub } from '../vue/context'

/**
 * THE CLIENT AND THE THEME, FOR EVERYTHING BELOW.
 *
 * ⚠️ THIS COMPONENT RENDERS NOTHING OF ITS OWN — no wrapper element, no class. A provider that
 * introduces a `<div>` changes the layout of whatever it is dropped into, and the host discovers
 * it as a broken grid rather than as a decision anybody took.
 *
 * ⚠️ USE IT, OR THE PLUGIN — never wonder which. `createMediaHub()` installs the same two values
 * application-wide and is the ordinary case; this component exists for the host that runs two
 * libraries side by side, or that only wants MediaHub on one screen.
 */
const props = defineProps<{
    client: MediaHubClient
    /**
     * ⚠️ REACTIVE, unlike the client. Switching to a dark palette, or letting somebody pick a
     * brand at runtime, has to reach components that are already mounted.
     */
    theme?: MhThemeOverride
    /** A shipped language (`en`, `fr`). Reactive, like the theme. */
    locale?: string
    /** Or an engine of your own — `vue-i18n`, or anything with the same shape. */
    text?: MhTranslator
}>()

/*
 * ⚠️ THE CLIENT IS TAKEN ONCE, AT MOUNT, and swapping it afterwards has no effect — Vue's
 * injection carries the value, not the reference. That is a real limitation and it is written
 * here rather than discovered: a host that changes tenant should key this component on the
 * tenant, so that the tree is rebuilt rather than half-updated.
 */
provideMediaHub(props.client)

provideMediaTheme(() => props.theme)

provideMediaText(props.text ?? createTranslator(() => props.locale))
</script>

<template>
    <slot />
</template>
