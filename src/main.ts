// IMPORTANT: this block must stay first, before any other module-level
// statement (and before the imports above resolve their side effects).
// Nextcloud installs may serve apps from `/apps/<id>/` or `/custom_apps/<id>/`
// depending on the admin's `apps_paths` config. Webpack's static
// publicPath only knows about the first; for any chunk webpack loads at
// runtime we redirect it through OC.appswebroots, which Nextcloud
// guarantees is correct for the current install.
/**
 * Entry point loaded by Nextcloud as an init script (see
 * {@link \OCA\ExeLearning\AppInfo\Application::boot}). It runs before the
 * Files app finishes booting, so a `.elpx` click finds our actions
 * immediately.
 *
 * Clicking a `.elpx` jumps to `/apps/exelearning/view` via the default
 * Files action (see src/files/actions.ts). The Viewer handler path is
 * intentionally not used.
 */

import { registerFileActions } from './files/actions'
import { registerNewMenuEntry } from './files/new-menu'

declare let __webpack_public_path__: string
declare global {
	interface Window {
		OC?: { appswebroots?: Record<string, string> }
	}
}
if (typeof window !== 'undefined' && window.OC?.appswebroots?.exelearning) {
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	__webpack_public_path__ = `${window.OC.appswebroots.exelearning}/js/`
}

/**
 *
 */
function safeBoot(): void {
	try {
		registerFileActions()
	} catch (error) {
		// Files action registration can run before @nextcloud/files is ready
		// in some embeds (e.g. public share pages). Swallow so we never break
		// the host page.
		// eslint-disable-next-line no-console
		console.warn('[exelearning] file action registration failed:', error)
	}
	try {
		registerNewMenuEntry()
	} catch (error) {
		// Same defensive guard — the New menu registry isn't available on
		// public share pages and similar embeds.
		// eslint-disable-next-line no-console
		console.warn('[exelearning] new-menu registration failed:', error)
	}
}

safeBoot()
