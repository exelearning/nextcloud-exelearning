/**
 * Builds the sandboxed iframe that displays a session. The iframe is the only
 * point where package HTML executes, and the sandbox flags are tuned to the
 * minimum that still lets eXeLearning's iDevices run.
 *
 * External links inside the iframe are forced to open in a new tab so the
 * parent Nextcloud window never navigates away from the Viewer.
 */

import { buildRuntimeUrl } from './paths'

const SANDBOX_FLAGS = [
	'allow-scripts',
	'allow-same-origin',
	'allow-forms',
	'allow-popups',
	'allow-downloads',
	'allow-popups-to-escape-sandbox',
] as const

const IFRAME_ALLOW = [
	'fullscreen',
	'autoplay',
	'clipboard-read',
	'clipboard-write',
].join('; ')

/**
 * URL parameter that eXeLearning packages read to expose the in-package
 * "teacher layer" selector (see exelearning/exelearning#1972). Without it,
 * exported packages hide teacher-only content and offer no way to reveal it.
 *
 * The Nextcloud Viewer is a personal file viewer — the person opening the
 * package is effectively its author/teacher — so we always make the selector
 * available. The selector itself stays OFF by default; the viewer activates
 * it and the package's own JS persists the choice and propagates the param
 * across in-package navigation links.
 */
const TEACHER_MODE_PARAM = 'exe-teacher=1'

/**
 * Appends {@link TEACHER_MODE_PARAM} to the top-level package index URL,
 * preserving any existing query string and avoiding a double append.
 * @param src Already-resolved index URL the iframe will load.
 */
function withTeacherMode(src: string): string {
	if (src.includes(TEACHER_MODE_PARAM)) {
		return src
	}
	return src + (src.includes('?') ? '&' : '?') + TEACHER_MODE_PARAM
}

export interface IframeOptions {
	runtimeBase: string
	sessionId: string
	indexEntry: string
	title: string
}

/**
 * Builds a sandboxed iframe pointed at an already-resolved `src`. Shared by the
 * Service Worker path ({@link createPackageIframe}) and the server-side asset
 * fallback so both get identical sandbox flags and external-link rewiring.
 * @param src Fully-resolved URL the iframe should load.
 * @param title Accessible title for the iframe.
 */
export function buildSandboxedIframe(src: string, title: string): HTMLIFrameElement {
	const iframe = document.createElement('iframe')
	iframe.className = 'exelearning-viewer__iframe'
	iframe.title = title
	iframe.setAttribute('sandbox', SANDBOX_FLAGS.join(' '))
	iframe.setAttribute('allow', IFRAME_ALLOW)
	iframe.setAttribute('referrerpolicy', 'no-referrer')
	// `src` is always the package *index* entry — both the Service Worker path
	// ({@link createPackageIframe}) and the server-side asset fallback funnel
	// the top-level page here, never a subresource. Adding the teacher-mode
	// param only to this top-level document is enough: the package's own JS
	// propagates it across in-package navigation, and the SW/AssetController
	// match requests on the pathname only, so the extra query is harmless.
	iframe.src = withTeacherMode(src)
	iframe.addEventListener('load', () => {
		try {
			rewireExternalLinks(iframe)
		} catch {
			// Cross-origin error: we lose the same-origin invariant when the
			// SW is bypassed. We swallow the error rather than break the view.
		}
	})
	return iframe
}

/**
 * Builds the sandboxed iframe that renders a registered package session.
 * The `src` is built from the runtime base + sessionId + index entry; the
 * Service Worker fulfils requests under that scope from in-memory bytes.
 * @param options Runtime base, sessionId, index entry and accessible title.
 */
export function createPackageIframe(options: IframeOptions): HTMLIFrameElement {
	return buildSandboxedIframe(
		buildRuntimeUrl(options.runtimeBase, options.sessionId, options.indexEntry),
		options.title,
	)
}

/**
 * Forces every external (`scheme:` or `//host`) link inside the iframe to
 * open in a new tab with `noopener noreferrer`, so the Viewer modal never
 * navigates away from Nextcloud.
 * @param iframe Iframe whose document should be augmented.
 */
function rewireExternalLinks(iframe: HTMLIFrameElement): void {
	const doc = iframe.contentDocument
	if (!doc) return
	doc.addEventListener('click', (event) => {
		const target = event.target instanceof HTMLElement ? event.target.closest('a') : null
		if (!target) return
		const href = target.getAttribute('href')
		if (!href) return
		if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(href)) {
			target.setAttribute('target', '_blank')
			target.setAttribute('rel', 'noopener noreferrer')
		}
	}, true)
}
