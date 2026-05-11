/**
 * Client-side bridge to the eXeLearning runtime Service Worker.
 *
 * The Service Worker only intercepts URLs under {@link runtimeBase}. Packages
 * are not in any HTTP cache: the page hands the extracted bytes to the SW via
 * postMessage, the SW stores them in memory, and the iframe loads
 * `${runtimeBase}/{sessionId}/index.html`.
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
 *
 * @param registration
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
 *
 * @param worker
 * @param session
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
 *
 * @param worker
 * @param sessionId
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
 *
 * @param target
 * @param message
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
