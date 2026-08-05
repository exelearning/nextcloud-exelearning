/**
 * Path helpers shared between the loader, the service worker client, the
 * iframe renderer, and the package validator. Everything that touches a ZIP
 * entry path or builds a virtual runtime URL goes through this module so the
 * entry-path rule lives in one place.
 */

export const RUNTIME_PREFIX = '/apps/exelearning/runtime'
export const ASSET_PREFIX = '/apps/exelearning/asset'

const PROTOCOL_LIKE = /^[a-zA-Z][a-zA-Z0-9+.-]*:/

/**
 * Validates a ZIP entry path and returns it **unchanged**, or null when it is
 * not already a canonical path inside the package.
 *
 * This function never rewrites its input: an accepted path is byte-identical
 * to the one passed in. A path is accepted only when it is non-empty, free of
 * NUL bytes and backslashes, and made exclusively of segments that are
 * non-empty and are neither `.` nor `..`. That rejects leading, doubled and
 * trailing slashes, dot segments, and Windows separators.
 *
 * Rewriting would be unsound here. Entry names are looked up verbatim in the
 * archive (`ZipArchive::statName()` on the PHP side, a `Map` keyed by the
 * stored name in the browser), so turning `a/b/../c` into `a/c` addresses a
 * different entry than the one that was requested. URL parsers also strip dot
 * segments before a request is ever dispatched (RFC 3986 §5.2.4), which means
 * an entry whose stored name contains one cannot be addressed over the
 * runtime URL scheme at all.
 *
 * `lib/Service/ZipEntryService.php::normalizeEntry` and the inline mirror in
 * `src/sw/exelearning-sw.js` implement exactly this rule. All three are tested
 * against the shared table in `tests/fixtures/entry-path-vectors.json`; see
 * `docs/architecture/adr/ADR-96-01-validate-entry-paths-instead-of-rewriting-them.md`.
 * @param input Raw entry path as it appears in the archive or in a request.
 */
export function normalizeEntryPath(input: string): string | null {
	if (input.length === 0 || input.includes('\0') || input.includes('\\')) {
		return null
	}
	for (const segment of input.split('/')) {
		if (segment === '' || segment === '.' || segment === '..') {
			return null
		}
	}
	return input
}

/**
 * Applies RFC 3986 §5.2.4 dot-segment removal to a package-relative path.
 * Returns null when a `..` segment would climb above the package root.
 *
 * Resolution is deliberately separate from {@link normalizeEntryPath}: an
 * href written inside package HTML may legitimately contain `./` and `../`,
 * while a *stored entry name* may not. Only hrefs go through here, and the
 * result is still validated before it is used.
 * @param path Package-relative path that may contain `.`/`..` segments.
 */
function removeDotSegments(path: string): string | null {
	const stack: string[] = []
	for (const segment of path.split('/')) {
		if (segment === '.') {
			continue
		}
		if (segment === '..') {
			// `..` is only legitimate when it cancels a directory we have
			// already entered. An attempt to escape the package root returns
			// null so callers can reject the href outright.
			if (stack.length === 0) {
				return null
			}
			stack.pop()
			continue
		}
		stack.push(segment)
	}
	return stack.join('/')
}

/**
 * Resolves a relative resource href against a base entry path (e.g. when the
 * iframe-loaded page navigates to `./html/page.html`). Returns null if the
 * resolution escapes the package root or does not land on a valid entry.
 * @param baseEntry Entry path of the page that contains the link.
 * @param href Relative href from inside the package HTML (`./foo`, `../bar`, …).
 */
export function resolveRelativeEntry(baseEntry: string, href: string): string | null {
	if (isExternalUrl(href)) {
		return null
	}
	// A leading slash addresses the package root rather than the directory
	// containing the page, which is how a browser resolves it against the
	// package's own base URL.
	const fromRoot = href.startsWith('/')
	const baseDir = !fromRoot && baseEntry.includes('/')
		? baseEntry.slice(0, baseEntry.lastIndexOf('/'))
		: ''
	let combined: string
	if (fromRoot) {
		combined = href.slice(1)
	} else {
		combined = baseDir ? `${baseDir}/${href}` : href
	}
	const resolved = removeDotSegments(combined)
	if (resolved === null) {
		return null
	}
	return normalizeEntryPath(resolved)
}

/**
 * True when `href` points at something that should leave the package
 * sandbox: protocol-relative URLs (`//host/…`) and absolute URLs whose
 * scheme is not `data:` or `blob:` (mailto:, tel:, http(s), ftp, …).
 * @param href Raw href attribute as found in the package HTML.
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
 * @param base Runtime base URL (typically the value returned by
 * `generateUrl(RUNTIME_PREFIX)` so it works under both pretty and
 * `index.php`-style Nextcloud URLs).
 * @param sessionId Opaque session id registered with the Service Worker.
 * @param entry Normalised entry path inside the package.
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
 * Builds an iframe-loadable URL for an entry served by the **server-side**
 * AssetController. Used as a fallback when the runtime Service Worker can't be
 * registered (e.g. Nextcloud embedded in another origin, like the Playground,
 * where the browser fetches the SW script straight from the network and 404s).
 *
 * `fileId` is the Nextcloud file id; the server re-checks the user can read it
 * and extracts the requested entry from the stored package on demand.
 * @param base Asset base URL (typically `generateUrl(ASSET_PREFIX)`).
 * @param fileId Nextcloud file id of the `.elpx` package.
 * @param entry Normalised entry path inside the package.
 */
export function buildAssetUrl(base: string, fileId: number, entry: string): string {
	const normalized = normalizeEntryPath(entry)
	if (normalized === null) {
		throw new Error(`Refusing to build asset URL for unsafe entry: ${entry}`)
	}
	const cleanBase = base.replace(/\/+$/, '')
	return `${cleanBase}/${encodeURIComponent(String(fileId))}/${normalized
		.split('/')
		.map(encodeURIComponent)
		.join('/')}`
}

/**
 * Parses a runtime URL produced by {@link buildRuntimeUrl} back into its
 * session and entry components. The base path must match RUNTIME_PREFIX.
 * @param url Full URL or pathname to parse.
 * @param base Runtime base URL the SW is registered against.
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
