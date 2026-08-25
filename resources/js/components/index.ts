/*
 * LAYER 3 — THE COMPONENTS, WITH FROZEN MARKUP.
 *
 * ⚠️ THESE ARE NOT MEANT TO BE PUBLISHED AND EDITED, and that is the trade. A view that can be
 * forked is a view that gets forked, and from that day the package cannot change anything
 * without breaking the copy — which is the story that made this package necessary in the first
 * place. In exchange, everything about the appearance is settable from the outside: tokens for
 * the ordinary case, the class table for the rest, and named slots for content.
 *
 * ⚠️ WHOEVER NEEDS A DIFFERENT STRUCTURE WRITES THEIR OWN, on the composables of layer 2, which
 * carry all of the logic and none of the markup. That is the escape hatch, and it is a supported
 * one — not a workaround.
 */

export { createMediaHub } from './install'
export type { MediaHubOptions, MediaHubPlugin } from './install'

export { default as MhProvider } from './MhProvider.vue'

export { default as MhThumbnail } from './MhThumbnail.vue'
export { default as MhEmptyState } from './MhEmptyState.vue'
export { default as MhSkeleton } from './MhSkeleton.vue'
export { default as MhErrorState } from './MhErrorState.vue'
export { default as MhConfirmDialog } from './MhConfirmDialog.vue'

export { default as MhItemCard } from './MhItemCard.vue'
export { default as MhItemGrid } from './MhItemGrid.vue'
export { default as MhMediaPicker } from './MhMediaPicker.vue'
export { default as MhMediaInput } from './MhMediaInput.vue'
export { default as MhMediaGallery } from './MhMediaGallery.vue'

export { default as MhSelectionBar } from './MhSelectionBar.vue'
export { default as MhContextMenu } from './MhContextMenu.vue'
export { default as MhDropzone } from './MhDropzone.vue'
export { default as MhUploadButton } from './MhUploadButton.vue'
export { default as MhFolderCreator } from './MhFolderCreator.vue'
export { default as MhUploadQueue } from './MhUploadQueue.vue'
export { default as MhPager } from './MhPager.vue'
export { default as MhQuotaMeter } from './MhQuotaMeter.vue'
export { default as MhDetailsPanel } from './MhDetailsPanel.vue'
export { default as MhDetailsDialog } from './MhDetailsDialog.vue'
export { default as MhBreadcrumb } from './MhBreadcrumb.vue'
export { default as MhToolbar } from './MhToolbar.vue'
export { default as MhFolderList } from './MhFolderList.vue'
export { default as MhMediaLibrary } from './MhMediaLibrary.vue'

export { defaultActions, useMediaActionList } from './actions'
export type { MhAction, UseMediaActionList } from './actions'
export { useActionRunner } from './useActionRunner'
export type { UseActionRunner } from './useActionRunner'


/*
 * ⚠️ THE WORDS ARE PART OF THE PUBLIC SURFACE, and they have to be. With the markup frozen,
 * translating is the only way to change a label — so the catalogue, the translator and the
 * hook that reads it are as much of a contract as the components themselves.
 */
export { createTranslator, mediaTextKey, provideMediaText, useMediaText } from '../i18n/context'
export type { MhTranslator } from '../i18n/context'
export { MH_DEFAULT_LOCALE, MH_LOCALES } from '../i18n/messages'
export type { MhLocale, MhMessages } from '../i18n/messages'

export { defaultTheme } from '../theme/defaults'
export { mergeTheme, classesOf } from '../theme/merge'
export { mediaThemeKey, provideMediaTheme, useMediaTheme } from '../theme/context'
export type {
    MhClasses,
    MhComponentOverride,
    MhComponentStyle,
    MhSlotStyle,
    MhTheme,
    MhThemeOverride,
} from '../theme/types'
