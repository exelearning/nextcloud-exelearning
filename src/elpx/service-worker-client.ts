/**
 * Client-side bridge to the eXeLearning runtime Service Worker.
 *
 * The Service Worker only intercepts URLs under the `runtimeBase` returned
 * by {@link ensureRuntimeWorker}. Packages are not in any HTTP cache: the
 * page hands the extracted bytes to the SW via postMessage, the SW stores
 * them in memory, and the iframe loads `<runtimeBase>/<sessionId>/index.html`.
 *
 * If Service Workers are unavailable (insecure context, browser policy, …)
 * {@link ensureRuntimeWorker} throws and the viewer surfaces a clear error
 * instead of silently falling back.
 */

import { generateUrl } from '@nextcloud/router'
import type { ViewerSession } from './viewer-session'
import { RUNTIME_PREFIX } from './paths'

export interface RuntimeWorker {
	registration: ServiceWorkerRegistration
	scriptUrl: string
	scope: string
	runtimeBase: string
}

let activeWorker: RuntimeWorker | null = null
let pendingRegistration: Promise<RuntimeWorker> | null = null

/**
 *
 */
export async function ensureRuntimeWorker(): Promise<RuntimeWorker> {
	if (activeWorker) {
		return activeWorker
	}
	if (pendingRegistration) {
		return pendingRegistration
	}
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		throw new Error('Service Workers are not available in this browser context.')
	}
	pendingRegistration = registerRuntimeWorker().finally(() => {
		pendingRegistration = null
	})
	return pendingRegistration
}

/**
 *
 */
async function registerRuntimeWorker(): Promise<RuntimeWorker> {
	const scriptUrl = generateUrl('/apps/exelearning/sw.js')
	const scope = `${generateUrl(RUNTIME_PREFIX)}/`
	const runtimeBase = generateUrl(RUNTIME_PREFIX)
	const registration = await navigator.serviceWorker.register(scriptUrl, {
		scope,
		type: 'classic',
	})
	await waitForActive(registration)
	const worker: RuntimeWorker = { registration, scriptUrl, scope, runtimeBase }
	activeWorker = worker
	return worker
}

/**
 * Resolves once the registered Service Worker reaches the `activated`
 * state. Resolves immediately when the worker is already controlling the
 * page; otherwise listens for `statechange` on the
 * installing/waiting/active worker.
 * @param registration Result of `navigator.serviceWorker.register`.
 */
function waitForActive(registration: ServiceWorkerRegistration): Promise<void> {
	if (registration.active && navigator.serviceWorker.controller) {
		return Promise.resolve()
	}
	return new Promise((resolve) => {
		const sw = registration.installing || registration.waiting || registration.active
		if (!sw) {
			resolve()
			return
		}
		if (sw.state === 'activated') {
			resolve()
			return
		}
		sw.addEventListener('statechange', () => {
			if (sw.state === 'activated') {
				resolve()
			}
		})
	})
}

/**
 * Hands the extracted package bytes to the Service Worker so subsequent
 * `<runtimeBase>/<sessionId>/<entry>` requests can be served from memory.
 * Each entry is sliced into a fresh `ArrayBuffer` so the SW does not
 * retain references to the page's typed-array views.
 * @param worker Active runtime worker returned by {@link ensureRuntimeWorker}.
 * @param session Viewer session containing the decompressed entries.
 */
export async function registerSession(worker: RuntimeWorker, session: ViewerSession): Promise<void> {
	const target = worker.registration.active ?? worker.registration.waiting ?? worker.registration.installing
	if (!target) {
		throw new Error('The eXeLearning Service Worker is not active.')
	}
	const files: Array<{ path: string; mime: string; bytes: ArrayBuffer }> = []
	for (const [path, file] of session.data.files) {
		const sliced = file.bytes.buffer.slice(
			file.bytes.byteOffset,
			file.bytes.byteOffset + file.bytes.byteLength,
		)
		files.push({
			path,
			mime: file.mime,
			bytes: sliced as ArrayBuffer,
		})
	}
	await postWithReply(target, {
		type: 'EXELEARNING_REGISTER_SESSION',
		sessionId: session.id,
		indexEntry: session.indexEntry,
		filename: session.filename,
		files,
	})
}

/**
 * Asks the Service Worker to drop a session. Cleanup failures are
 * intentionally swallowed — closing the tab terminates the SW shortly
 * after and clears the entry anyway.
 * @param worker Active runtime worker returned by {@link ensureRuntimeWorker}.
 * @param sessionId Session id originally passed to {@link registerSession}.
 */
export async function unregisterSession(worker: RuntimeWorker, sessionId: string): Promise<void> {
	const target = worker.registration.active ?? worker.registration.waiting ?? worker.registration.installing
	if (!target) {
		return
	}
	try {
		await postWithReply(target, { type: 'EXELEARNING_UNREGISTER_SESSION', sessionId })
	} catch {
		// Session cleanup failures must not surface to the user — the SW will
		// drop the entry eventually when the tab closes anyway.
	}
}

interface RuntimeMessage {
	type: string
	[key: string]: unknown
}

/**
 * `postMessage` wrapper that resolves on the SW's `{ ok: true }` reply and
 * rejects on `{ ok: false, error }`. A single-use `MessageChannel` carries
 * the response so requests do not interfere with each other.
 * @param target Service Worker that should receive the message.
 * @param message Request envelope (must include a `type`).
 */
function postWithReply(target: ServiceWorker, message: RuntimeMessage): Promise<void> {
	return new Promise((resolve, reject) => {
		const channel = new MessageChannel()
		channel.port1.onmessage = (event) => {
			channel.port1.close()
			const data = event.data as { ok?: boolean; error?: string } | undefined
			if (data?.ok) {
				resolve()
			} else {
				reject(new Error(data?.error ?? 'Service Worker rejected the message.'))
			}
		}
		try {
			target.postMessage(message, [channel.port2])
		} catch (error) {
			channel.port1.close()
			reject(error instanceof Error ? error : new Error(String(error)))
		}
	})
}
