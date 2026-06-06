// Same publicPath fix as main.ts.
import { createApp } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

import ElpxViewPage from './ElpxViewPage.vue'

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

interface InitialFile {
	id: number
	name: string
	path: string
	mtime: number
	etag: string
	writable: boolean
}

/**
 * Wraps `loadState` so a missing or malformed initial-state entry falls
 * back to the supplied default instead of throwing during page boot.
 * @param key The initial-state key registered server-side.
 * @param fallback Value to return when the key is missing or invalid.
 */
function safeLoad<T>(key: string, fallback: T): T {
	try {
		return (loadState('exelearning', key, fallback) as T) ?? fallback
	} catch {
		return fallback
	}
}

/**
 *
 */
function boot(): void {
	const mount = document.getElementById('exelearning-view-root')
	if (!mount) return

	const file = safeLoad<InitialFile | null>('file', null)
	const editorAvailable = safeLoad<boolean>('editorAvailable', false)
	// Build the editor iframe URL client-side so it carries the correct webroot.
	// The server-rendered value (via initial state) is computed with an empty
	// webroot when Nextcloud is served under a sub-path (e.g. the browser
	// Playground scopes everything under /playground/<scope>/…); using it as the
	// iframe src verbatim would escape that scope and 404. generateUrl() resolves
	// against the live OC.webroot, which is correct in both cases.
	const editorIframeUrl = generateUrl('/apps/exelearning/editor/iframe')
	const initialMode = safeLoad<'preview' | 'editor'>('initialMode', 'preview')

	createApp(ElpxViewPage, { file, editorAvailable, editorIframeUrl, initialMode })
		.mount(mount)
}

boot()
