/*
 * LAYER 2 — COMPOSABLES, NO MARKUP.
 *
 * ⚠️ FOR SOMEONE WHO WANTS THEIR OWN SCREEN. Everything here is state and operations; nothing
 * renders. The acceptance criterion for this layer is written in the design notes and is worth
 * repeating: writing an entirely different interface on top of it should be a day's work. If it
 * is not, the layer is cut wrong — and that is found out here, not once components exist.
 */

export { mediaHubKey, provideMediaHub, resolveMediaHub } from './context'

export { useMediaBrowser } from './useMediaBrowser'
export type { UseMediaBrowser } from './useMediaBrowser'

export { useFolders } from './useFolders'
export type { UseFolders } from './useFolders'

export { useSelection } from './useSelection'
export type { SelectableKind, UseSelection } from './useSelection'

export { useUpload } from './useUpload'
export type { UseUpload } from './useUpload'

export { useMediaActions } from './useMediaActions'
export type { UseMediaActions } from './useMediaActions'

export { useMediaPicker } from './useMediaPicker'
export type { MediaPickerRequest, UseMediaPicker } from './useMediaPicker'

export { useQuota } from './useQuota'
export type { UseQuota } from './useQuota'
