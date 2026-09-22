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
