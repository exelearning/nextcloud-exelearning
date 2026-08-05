/**
 * eXeLearning runtime Service Worker.
 *
 * Scope: /apps/exelearning/runtime/ (or /index.php/apps/exelearning/runtime/
 * depending on Nextcloud URL rewriting).
 *
 * Responsibilities:
 *   - Accept extracted package bytes from the page over postMessage.
 *   - Serve `${scope}/{sessionId}/{entry}` requests from that in-memory map.
 *   - Stay out of the way for everything else (we only see in-scope fetches,
 *     but we double-check the URL anyway).
 *
 * Sessions are kept only in this worker's memory. Closing the tab (and the
 * subsequent SW termination) drops them. The page is the source of truth.
 *
 * This file is shipped as-is — not bundled by webpack — and served by
 * SwController so the response can set Content-Type and Service-Worker-Allowed.
 */

/* eslint-env serviceworker */
/* global self */

const sessions = new Map()

const RUNTIME_TAIL = '/apps/exelearning/runtime/'

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim())
})

self.addEventListener('message', (event) => {
	const port = event.ports?.[0]
	const reply = (payload) => {
		if (port) {
			try { port.postMessage(payload) } catch (e) { /* port closed */ void e }
		}
	}
	const data = event.data
	if (!data || typeof data.type !== 'string') {
		reply({ ok: false, error: 'Invalid message' })
		return
	}
	try {
		switch (data.type) {
		case 'EXELEARNING_REGISTER_SESSION':
			registerSession(data)
			reply({ ok: true })
			break
		case 'EXELEARNING_UNREGISTER_SESSION':
			sessions.delete(data.sessionId)
			reply({ ok: true })
			break
		case 'EXELEARNING_PING':
			reply({ ok: true, sessions: sessions.size })
			break
		default:
			reply({ ok: false, error: `Unknown message type: ${data.type}` })
		}
	} catch (error) {
		reply({ ok: false, error: error?.message ? error.message : String(error) })
	}
})

/**
 * Stores a new session in the in-memory map. Files arrive as a list of
 * `{ path, mime, bytes }`; the path is normalised here so request matching
 * never has to deal with `..`/`.` segments or backslashes.
 * @param {{ sessionId: string, files: Array<{ path: string, mime?: string, bytes: ArrayBuffer | null }>, indexEntry?: string, filename?: string }} data
 *   `EXELEARNING_REGISTER_SESSION` message payload from the page.
 */
function registerSession(data) {
	if (!data.sessionId || typeof data.sessionId !== 'string') {
		throw new Error('Missing sessionId')
	}
	if (!Array.isArray(data.files)) {
		throw new Error('Missing files array')
	}
	const files = new Map()
	for (const entry of data.files) {
		if (!entry || typeof entry.path !== 'string') continue
		const path = normalizeEntry(entry.path)
		if (path === null) continue
		files.set(path, {
			mime: typeof entry.mime === 'string' ? entry.mime : 'application/octet-stream',
			bytes: entry.bytes instanceof ArrayBuffer ? entry.bytes : null,
		})
	}
	sessions.set(data.sessionId, {
		files,
		indexEntry: typeof data.indexEntry === 'string' ? data.indexEntry : 'index.html',
		filename: typeof data.filename === 'string' ? data.filename : '',
		createdAt: Date.now(),
	})
}

self.addEventListener('fetch', (event) => {
	const request = event.request
	if (request.method !== 'GET') return
	const url = new URL(request.url)
	const match = matchRuntimeUrl(url.pathname)
	if (!match) return
	event.respondWith(handleRuntimeFetch(match))
})

/**
 * Looks up the session and entry produced by {@link matchRuntimeUrl} and
 * builds the `Response` for the iframe. Missing sessions return 410 (the
 * package was unloaded), missing entries return 404.
 * @param {{ sessionId: string, entry: string }} match
 *   Parsed runtime URL from {@link matchRuntimeUrl}.
 */
function handleRuntimeFetch(match) {
	const session = sessions.get(match.sessionId)
	if (!session) {
		return new Response('Session not registered. Reload the package.', {
			status: 410,
			headers: { 'Content-Type': 'text/plain; charset=utf-8' },
		})
	}
	const file = session.files.get(match.entry)
	if (!file || !file.bytes) {
		return new Response(`Not found in package: ${match.entry}`, {
			status: 404,
			headers: { 'Content-Type': 'text/plain; charset=utf-8' },
		})
	}
	const headers = new Headers({
		'Content-Type': file.mime,
		'Content-Length': String(file.bytes.byteLength),
		'X-Content-Type-Options': 'nosniff',
		'Cache-Control': 'private, no-store',
		// Keep package HTML isolated from the parent Nextcloud window even
		// though the iframe sandbox already blocks top navigation.
		'Content-Security-Policy': "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:; img-src 'self' data: blob:; media-src 'self' data: blob:; frame-ancestors 'self'",
	})
	return new Response(file.bytes, { status: 200, headers })
}

/**
 * Splits a request pathname into `{ sessionId, entry }` if it falls under
 * the runtime scope, or returns `null` so the SW lets the request through
 * to the network unchanged.
 * @param {string} pathname `URL.pathname` of the intercepted request.
 */
function matchRuntimeUrl(pathname) {
	const idx = pathname.indexOf(RUNTIME_TAIL)
	if (idx < 0) return null
	const remainder = pathname.slice(idx + RUNTIME_TAIL.length)
	const slash = remainder.indexOf('/')
	if (slash <= 0) return null
	const sessionId = safeDecode(remainder.slice(0, slash))
	const rawEntry = remainder.slice(slash + 1)
	const decoded = rawEntry
		.split('/')
		.map(safeDecode)
		.join('/')
	const entry = normalizeEntry(decoded)
	if (!sessionId || entry === null) return null
	return { sessionId, entry }
}

/**
 * `decodeURIComponent` that returns the raw input on malformed escape
 * sequences instead of throwing — the SW must never crash on adversarial
 * URLs.
 * @param {string} value Single URL path segment to decode.
 */
function safeDecode(value) {
	try {
		return decodeURIComponent(value)
	} catch (e) {
		void e
		return value
	}
}

/**
 * SW-side mirror of `normalizeEntryPath` from src/elpx/paths.ts. Kept
 * inline because the SW must not import from the bundled application
 * code (it is loaded out-of-band by the browser, not by webpack).
 *
 * Validates and returns the input unchanged, or null. A path is accepted
 * only when it is non-empty, free of NUL bytes and backslashes, and made
 * exclusively of segments that are non-empty and are neither `.` nor `..`.
 * Nothing is rewritten: entries are looked up by their stored name, so
 * turning `a/b/../c` into `a/c` would serve a different file than the one
 * requested.
 *
 * The same rule lives in `src/elpx/paths.ts` and in
 * `lib/Service/ZipEntryService.php`. All three are tested against
 * `tests/fixtures/entry-path-vectors.json` — change one and the shared
 * table fails until the other two follow. See
 * `docs/architecture/adr/ADR-XXXX-01-validate-entry-paths-instead-of-rewriting-them.md`.
 * @param {unknown} input Untrusted entry value coming from a request URL.
 */
function normalizeEntry(input) {
	if (typeof input !== 'string' || input.length === 0
		|| input.indexOf('\0') >= 0 || input.indexOf('\\') >= 0) {
		return null
	}
	for (const segment of input.split('/')) {
		if (segment === '' || segment === '.' || segment === '..') return null
	}
	return input
}
