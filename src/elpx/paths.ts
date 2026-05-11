/**
 * Path helpers shared between the loader, the service worker client, the
 * iframe renderer, and the package validator. Everything that touches a ZIP
 * entry path or builds a virtual runtime URL goes through this module so the
 * normalization rules live in one place.
 */

export const RUNTIME_PREFIX = '/apps/exelearning/runtime'

const PROTOCOL_LIKE = /^[a-zA-Z][a-zA-Z0-9+.-]*:/

/**
 * Returns a canonical, slash-separated path with no `.`/`..` segments and no
 * leading slash, or null if the input is not safe (path traversal, absolute
 * file URL, NUL byte, ...).
 *
 * This matches the rule used by the PHP-side {@see ZipEntryService}.
 * @param input
 */
export function normalizeEntryPath(input: string): string | null {
	if (input.length === 0 || input.includes('\0')) {
		return null
	}
	const replaced = input.replace(/\\/g, '/').replace(/^\/+/, '')
	const parts = replaced.split('/')
	const stack: string[] = []
	for (const part of parts) {
		if (part === '' || part === '.') {
			continue
		}
		if (part === '..') {
			// `..` is only legitimate when it cancels a directory we have
			// already entered. An attempt to escape the package root returns
			// null so callers can reject the path outright.
			if (stack.length === 0) {
				return null
			}
			stack.pop()
			continue
		}
		stack.push(part)
	}
	if (stack.length === 0) {
		return null
	}
	return stack.join('/')
}

/**
 * Resolves a relative resource href against a base entry path (e.g. when the
 * iframe-loaded page navigates to `./html/page.html`). Returns null if the
 * resolution escapes the package root.
 * @param baseEntry
 * @param href
 */
export function resolveRelativeEntry(baseEntry: string, href: string): string | null {
	if (isExternalUrl(href)) {
		return null
	}
	const baseDir = baseEntry.includes('/')
		? baseEntry.slice(0, baseEntry.lastIndexOf('/'))
		: ''
	const combined = baseDir ? `${baseDir}/${href}` : href
	return normalizeEntryPath(combined)
}

/**
 *
 * @param href
 */
export function isExternalUrl(href: string): boolean {
	if (href.startsWith('//')) return true
	if (PROTOCOL_LIKE.test(href)) {
		const scheme = href.slice(0, href.indexOf(':')).toLowerCase()
		// Anything that is not http(s)/data/blob is treated as external (mailto:, tel:, ...).
		return scheme !== 'data' && scheme !== 'blob'
	}
	return false
}

export interface RuntimeUrl {
	sessionId: string
	entry: string
}

/**
 * Builds an iframe-loadable URL for an entry inside the package. The Service
 * Worker scope must match {@link RUNTIME_PREFIX}.
 * @param base
 * @param sessionId
 * @param entry
 */
export function buildRuntimeUrl(base: string, sessionId: string, entry: string): string {
	const normalized = normalizeEntryPath(entry)
	if (normalized === null) {
		throw new Error(`Refusing to build runtime URL for unsafe entry: ${entry}`)
	}
	const cleanBase = base.replace(/\/+$/, '')
	return `${cleanBase}/${encodeURIComponent(sessionId)}/${normalized
		.split('/')
		.map(encodeURIComponent)
		.join('/')}`
}

/**
 * Parses a runtime URL produced by {@link buildRuntimeUrl} back into its
 * session and entry components. The base path must match RUNTIME_PREFIX.
 * @param url
 * @param base
 */
export function parseRuntimeUrl(url: string, base: string): RuntimeUrl | null {
	const pathname = url.startsWith('/') ? url : new URL(url, 'http://placeholder/').pathname
	const cleanBase = base.replace(/\/+$/, '')
	if (!pathname.startsWith(`${cleanBase}/`)) {
		return null
	}
	const remainder = pathname.slice(cleanBase.length + 1)
	const slash = remainder.indexOf('/')
	if (slash <= 0) {
		return null
	}
	const sessionId = decodeURIComponent(remainder.slice(0, slash))
	const rawEntry = remainder
		.slice(slash + 1)
		.split('/')
		.map(decodeURIComponent)
		.join('/')
	const entry = normalizeEntryPath(rawEntry)
	if (entry === null) {
		return null
	}
	return { sessionId, entry }
}
