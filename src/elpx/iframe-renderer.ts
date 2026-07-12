/**
 * Builds the sandboxed iframe that displays a package.
 *
 * The default (secure) path serves the package from the opaque `/content`
 * route: the iframe drops `allow-same-origin`, so the document runs in a
 * browser-enforced **opaque origin** and cannot reach the Nextcloud DOM,
 * cookies or storage. External media is handled by the eXe embed relay running
 * in the parent (see relay-host.ts), not by reaching into the iframe document.
 *
 * The legacy path (Service-Worker `/runtime` route, only via the
 * EXELEARNING_UNSAFE_LEGACY_IFRAME escape hatch) keeps `allow-same-origin` —
 * the SW can only control a same-origin document — and rewires external links
 * by touching the same-origin iframe document.
 */

import { buildAssetUrl, buildContentUrl, buildRuntimeUrl } from './paths'

/** Opaque, secure sandbox: no allow-same-origin (that absence is the isolation). */
const SECURE_SANDBOX_FLAGS = [
	'allow-scripts',
	'allow-popups',
	'allow-forms',
] as const

/** Legacy same-origin sandbox for the Service-Worker path only. */
const LEGACY_SANDBOX_FLAGS = [
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

export interface ContentIframeOptions {
	contentBase: string
	token: string
	indexEntry: string
	title: string
}

export interface AssetIframeOptions {
	assetBase: string
	fileId: number
	indexEntry: string
	title: string
}

/**
 * Builds a sandboxed iframe pointed at an already-resolved `src`.
 * @param src Fully-resolved URL the iframe should load.
 * @param title Accessible title for the iframe.
 * @param opaque When true (default) use the secure opaque sandbox and do not
 *   touch the iframe document; when false use the legacy same-origin sandbox
 *   (Service-Worker path) and rewire external links.
 */
export function buildSandboxedIframe(src: string, title: string, opaque = true): HTMLIFrameElement {
	const iframe = document.createElement('iframe')
	iframe.className = 'exelearning-viewer__iframe'
	iframe.title = title
	iframe.setAttribute('sandbox', (opaque ? SECURE_SANDBOX_FLAGS : LEGACY_SANDBOX_FLAGS).join(' '))
	iframe.setAttribute('allow', IFRAME_ALLOW)
	iframe.setAttribute('referrerpolicy', 'no-referrer')
	// `src` is always the package *index* entry. Adding the teacher-mode param
	// only to this top-level document is enough: the package's own JS propagates
	// it across in-package navigation, and the content/SW route matches requests
	// on the pathname only, so the extra query is harmless.
	iframe.src = withTeacherMode(src)
	if (!opaque) {
		iframe.addEventListener('load', () => {
			try {
				rewireExternalLinks(iframe)
			} catch {
				// Same-origin access can still fail; swallow rather than break the view.
			}
		})
	}
	return iframe
}

/**
 * Builds the opaque-origin iframe that renders a published package over the
 * `/content/{token}/…` capability route. This is the default (secure) path.
 * @param options Content base URL, capability token, index entry and title.
 */
export function createContentIframe(options: ContentIframeOptions): HTMLIFrameElement {
	return buildSandboxedIframe(
		buildContentUrl(options.contentBase, options.token, options.indexEntry),
		options.title,
		true,
	)
}

/**
 * Builds the legacy same-origin iframe that renders a registered package
 * session over the Service-Worker `/runtime` route. Only used when the
 * EXELEARNING_UNSAFE_LEGACY_IFRAME escape hatch is on.
 * @param options Runtime base, sessionId, index entry and accessible title.
 */
export function createPackageIframe(options: IframeOptions): HTMLIFrameElement {
	return buildSandboxedIframe(
		buildRuntimeUrl(options.runtimeBase, options.sessionId, options.indexEntry),
		options.title,
		false,
	)
}

/**
 * Builds the authenticated same-origin iframe used when Service Worker
 * registration is unavailable. AssetController checks the Nextcloud session,
 * so this path must retain `allow-same-origin`; the secure capability-backed
 * `/content` route remains the only opaque variant.
 * @param options Asset base URL, file id, index entry and accessible title.
 */
export function createAssetIframe(options: AssetIframeOptions): HTMLIFrameElement {
	return buildSandboxedIframe(
		buildAssetUrl(options.assetBase, options.fileId, options.indexEntry),
		options.title,
		false,
	)
}

/**
 * Forces every external (`scheme:` or `//host`) link inside the iframe to
 * open in a new tab with `noopener noreferrer`, so the Viewer never navigates
 * away from Nextcloud. Only usable on the same-origin (legacy) path.
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
