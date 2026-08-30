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

const sessions = new Map()

const RUNTIME_TAIL = '/apps/exelearning/runtime/'

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim())
})

self.addEventListener('message', (event) => {
	const message = event.data
	if (!message || typeof message !== 'object') return

	if (message.type === 'SESSION_OPEN') {
		const { sessionId, files } = message
		if (typeof sessionId !== 'string' || !Array.isArray(files)) return
		const entries = new Map()
		for (const file of files) {
			if (
				!file
				|| typeof file.path !== 'string'
				|| typeof file.mime !== 'string'
				|| !(file.bytes instanceof ArrayBuffer)
			) {
				continue
			}
			entries.set(file.path, {
				mime: file.mime,
				bytes: file.bytes,
			})
		}
		sessions.set(sessionId, { files: entries })
		return
	}

	if (message.type === 'SESSION_CLOSE') {
		const { sessionId } = message
		if (typeof sessionId === 'string') sessions.delete(sessionId)
	}
})

/**
 * Extract sessionId and package entry from a runtime URL.
 *
 * @param {URL} url The request URL.
 * @return {{ sessionId: string, entry: string } | null} Parsed request data.
 */
function parseRuntimeRequest(url) {
	const marker = url.pathname.indexOf(RUNTIME_TAIL)
	if (marker === -1) return null
	const tail = url.pathname.slice(marker + RUNTIME_TAIL.length)
	const slash = tail.indexOf('/')
	if (slash <= 0) return null
	const sessionId = decodeURIComponent(tail.slice(0, slash))
	const entry = decodeURIComponent(tail.slice(slash + 1))
	return { sessionId, entry }
}

self.addEventListener('fetch', (event) => {
	const url = new URL(event.request.url)
	const match = parseRuntimeRequest(url)
	if (!match) return

	event.respondWith(handleRuntimeRequest(event.request, match))
})

/**
 * Serve one file from an in-memory eXeLearning package session.
 *
 * @param {Request} request Incoming request.
 * @param {{ sessionId: string, entry: string }} match Parsed request data.
 * @return {Promise<Response>} Response for the requested package entry.
 */
async function handleRuntimeRequest(request, match) {
	if (request.method !== 'GET') {
		return new Response('Method not allowed', { status: 405 })
	}

	const session = sessions.get(match.sessionId)
	if (!session) {
		return new Response('Unknown eXeLearning session', { status: 404 })
	}

	const file = session.files.get(match.entry)
	if (!file || !file.bytes) {
		return new Response(`Not found in package: ${match.entry}`, {
			status: 404,
		})
	}

	return new Response(file.bytes, {
		status: 200,
		headers: {
			'Content-Type': file.mime || 'application/octet-stream',
			'Cache-Control': 'no-store',
		},
	})
}
