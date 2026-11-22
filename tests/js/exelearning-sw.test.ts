/**
 * Drives `src/sw/exelearning-sw.js` with a fake ServiceWorkerGlobalScope: the
 * worker is plain script loaded out-of-band by the browser, so the only way
 * to exercise it is to install its listeners on a stand-in `self`.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

type Listener = (event: unknown) => void

const listeners = new Map<string, Listener>()
const RUNTIME = 'https://cloud.example/apps/exelearning/runtime/'

interface FakeClient {
	postMessage: (message: unknown) => void
}
// Window clients the worker can see; each test sets its own.
let windowClients: FakeClient[] = []

beforeAll(async () => {
	vi.stubGlobal('self', {
		skipWaiting: () => {},
		clients: {
			claim: () => Promise.resolve(),
			matchAll: () => Promise.resolve(windowClients),
		},
		addEventListener: (type: string, listener: Listener) => {
			listeners.set(type, listener)
		},
	})
	// @ts-expect-error plain script, no type declarations
	await import('../../src/sw/exelearning-sw.js')
})

function registerSession(sessionId = 'session-1'): void {
	listeners.get('message')?.({
		ports: [{ postMessage: () => {} }],
		data: {
			type: 'EXELEARNING_REGISTER_SESSION',
			sessionId,
			indexEntry: 'index.html',
			filename: 'demo.elpx',
			files: [{ path: 'index.html', mime: 'text/html; charset=utf-8', bytes: new ArrayBuffer(4) }],
		},
	})
}

async function fetchEntry(entry: string, sessionId = 'session-1'): Promise<Response> {
	let response: Promise<Response> | undefined
	listeners.get('fetch')?.({
		request: { method: 'GET', url: `${RUNTIME}${sessionId}/${entry}` },
		respondWith: (value: Promise<Response>) => {
			response = value
		},
	})
	if (!response) throw new Error('the worker did not respond to the runtime URL')
	return response
}

describe('runtime service worker CSP', () => {
	const directives = async () => {
		registerSession()
		const response = await fetchEntry('index.html')
		return (response.headers.get('Content-Security-Policy') ?? '').split('; ')
	}
	const directive = async (name: string) =>
		((await directives()).find((part) => part.startsWith(`${name} `)) ?? '').split(' ')

	it('lets package HTML load externally hosted images, media and embeds', async () => {
		expect(await directive('img-src')).toContain('https:')
		expect(await directive('media-src')).toContain('https:')
		expect(await directive('frame-src')).toContain('https:')
	})

	it('allows only the player scripts that exported iDevices load', async () => {
		const scriptSrc = await directive('script-src')
		for (const origin of [
			'https://www.youtube.com',
			'https://www.youtube-nocookie.com',
			'https://youtu.be',
			'https://player.vimeo.com',
			'https://mediateca.educa.madrid.org',
			'https://cdn.jsdelivr.net',
		]) {
			expect(scriptSrc).toContain(origin)
		}
		expect(scriptSrc).not.toContain('https:')
	})

	it('matches the policy of the server-side asset fallback', async () => {
		const php = readFileSync(path.resolve(__dirname, '../../lib/Controller/AssetController.php'), 'utf8')
		for (const part of await directives()) {
			expect(php).toContain(part)
		}
	})
})

describe('runtime service worker session recovery', () => {
	afterEach(() => {
		windowClients = []
		vi.useRealTimers()
	})

	it('asks the page to re-register a session lost on worker restart', async () => {
		const asked: unknown[] = []
		windowClients = [
			{
				postMessage: (message) => {
					asked.push(message)
					registerSession('lost-session')
				},
			},
		]

		const response = await fetchEntry('index.html', 'lost-session')

		expect(asked).toEqual([{ type: 'EXELEARNING_SESSION_LOST', sessionId: 'lost-session' }])
		expect(response.status).toBe(200)
	})

	it('answers 410 at once when no page is open to restore the session', async () => {
		const response = await fetchEntry('index.html', 'orphan-session')
		expect(response.status).toBe(410)
	})

	it('answers 410 when no page restores the session in time', async () => {
		vi.useFakeTimers()
		windowClients = [{ postMessage: () => {} }]
		const pending = fetchEntry('index.html', 'ignored-session')
		await vi.advanceTimersByTimeAsync(60_000)
		expect((await pending).status).toBe(410)
	})
})
