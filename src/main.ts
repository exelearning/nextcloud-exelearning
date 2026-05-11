// IMPORTANT: this block must stay first, before any other module-level
// statement (and before the imports above resolve their side effects).
// Nextcloud installs may serve apps from `/apps/<id>/` or `/custom_apps/<id>/`
// depending on the admin's `apps_paths` config. Webpack's static
// publicPath only knows about the first; for any chunk webpack loads at
// runtime we redirect it through OC.appswebroots, which Nextcloud
// guarantees is correct for the current install.
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
 * Entry point loaded by Nextcloud as an init script (see
 * {@link \OCA\ExeLearning\AppInfo\Application::boot}). It runs before the
 * Viewer app probes for MIME-handler associations, so a `.elpx` click in the
 * Files app finds our handler immediately.
 *
 * Two things happen here:
 *   1. Register the Viewer handler for the eXeLearning MIME types.
 *   2. Register Files actions ("Open with eXeLearning viewer", optionally
 *      "Edit with eXeLearning", and a plain Download).
 */

import { registerFileActions } from './files/actions'

// The Viewer handler is intentionally not registered any more. The
// previous "Iniciar la presentación" toolbar comes from
// @nextcloud/viewer's own modal chrome and there is no public API on the
// handler to suppress that button or add a custom "Edit" affordance.
// Clicking a .elpx now goes straight to /apps/exelearning/editor via the
// default Files action (see src/files/actions.ts).
//
// The ElpxViewer.vue component is still in the bundle; it can be reused
// by the editor page as a read-only fallback when the static editor is
// not installed.

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
}

safeBoot()

export {}
