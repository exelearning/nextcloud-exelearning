import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))

/** Fake ServiceWorker whose state the test drives. */
function fakeWorker(state: ServiceWorkerState) {
	const worker = new EventTarget() as ServiceWorker & { state: ServiceWorkerState }
	worker.state = state
	return worker
}

async function loadClient(installing: ServiceWorker | null, active: ServiceWorker | null = null, controller: object | null = null) {
	vi.resetModules()
	const registration = { installing, waiting: null, active }
	vi.stubGlobal('navigator', {
		serviceWorker: { controller, register: vi.fn().mockResolvedValue(registration) },
	})
	return import('../../src/elpx/service-worker-client')
}

describe('ensureRuntimeWorker', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('resolves once the worker activates', async () => {
		const worker = fakeWorker('installing')
		const { ensureRuntimeWorker } = await loadClient(worker)

		const pending = ensureRuntimeWorker()
		await Promise.resolve()
		worker.state = 'activated'
		worker.dispatchEvent(new Event('statechange'))

		await expect(pending).resolves.toMatchObject({ scope: '/apps/exelearning/runtime/' })
	})

	it('resolves at once for an already active or controlling worker and caches it', async () => {
		const active = fakeWorker('activated')
		const controlled = await loadClient(null, active, {})
		const first = await controlled.ensureRuntimeWorker()
		expect(await controlled.ensureRuntimeWorker()).toBe(first)

		const plain = await loadClient(null, active)
		await expect(plain.ensureRuntimeWorker()).resolves.toBeDefined()

		const none = await loadClient(null)
		await expect(none.ensureRuntimeWorker()).resolves.toBeDefined()
	})

	it('shares one registration between concurrent callers', async () => {
		const { ensureRuntimeWorker } = await loadClient(null, fakeWorker('activated'))

		const [a, b] = await Promise.all([ensureRuntimeWorker(), ensureRuntimeWorker()])

		expect(a).toBe(b)
		expect(navigator.serviceWorker.register).toHaveBeenCalledTimes(1)
	})

	it('rejects when the worker becomes redundant so the viewer can fall back', async () => {
		const worker = fakeWorker('installing')
		const { ensureRuntimeWorker } = await loadClient(worker)

		const pending = ensureRuntimeWorker()
		await Promise.resolve()
		worker.state = 'redundant'
		worker.dispatchEvent(new Event('statechange'))

		await expect(pending).rejects.toThrow('failed to activate')
	})
})

describe('session messages', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	/** Active worker that answers every message on the transferred port. */
	function replyingWorker(reply: unknown) {
		const posted: Array<Record<string, unknown>> = []
		const worker = Object.assign(fakeWorker('activated'), {
			postMessage: (message: Record<string, unknown>, [port]: MessagePort[]) => {
				posted.push(message)
				port.postMessage(reply)
			},
		})
		const runtime = {
			registration: { active: worker, waiting: null, installing: null } as unknown as ServiceWorkerRegistration,
			scriptUrl: '', scope: '', runtimeBase: '',
		}
		return { runtime, posted }
	}

	const session = {
		id: 's1',
		indexEntry: 'index.html',
		filename: 'x.elpx',
		data: { files: new Map([['index.html', { mime: 'text/html', bytes: new Uint8Array([1, 2, 3]).subarray(1) }]]) },
	}

	it('sends each entry as its own ArrayBuffer and resolves on ok', async () => {
		const { registerSession } = await loadClient(fakeWorker('activated'))
		const { runtime, posted } = replyingWorker({ ok: true })

		await registerSession(runtime, session as never)

		expect(posted[0]).toMatchObject({ type: 'EXELEARNING_REGISTER_SESSION', sessionId: 's1', indexEntry: 'index.html' })
		const [file] = posted[0].files as Array<{ bytes: ArrayBuffer }>
		expect(new Uint8Array(file.bytes)).toEqual(new Uint8Array([2, 3]))
	})

	it('surfaces a rejection from the worker', async () => {
		const { registerSession } = await loadClient(fakeWorker('activated'))
		const { runtime } = replyingWorker({ ok: false, error: 'quota' })

		await expect(registerSession(runtime, session as never)).rejects.toThrow('quota')
	})

	it('rejects when there is no worker or posting fails', async () => {
		const { registerSession } = await loadClient(fakeWorker('activated'))
		const { runtime } = replyingWorker({})
		const gone = { ...runtime, registration: { active: null, waiting: null, installing: null } as unknown as ServiceWorkerRegistration }
		const broken = Object.assign(fakeWorker('activated'), {
			postMessage: () => {
				throw new Error('DataCloneError')
			},
		})
		const throwing = { ...runtime, registration: { active: broken } as unknown as ServiceWorkerRegistration }

		await expect(registerSession(gone, session as never)).rejects.toThrow('not active')
		await expect(registerSession(runtime, session as never)).rejects.toThrow('rejected the message')
		await expect(registerSession(throwing, session as never)).rejects.toThrow('DataCloneError')
	})

	it('swallows unregister failures and skips a missing worker', async () => {
		const { unregisterSession } = await loadClient(fakeWorker('activated'))
		const { runtime, posted } = replyingWorker({ ok: false })
		const gone = { ...runtime, registration: { active: null, waiting: null, installing: null } as unknown as ServiceWorkerRegistration }

		await expect(unregisterSession(runtime, 's1')).resolves.toBeUndefined()
		await expect(unregisterSession(gone, 's1')).resolves.toBeUndefined()
		expect(posted).toEqual([{ type: 'EXELEARNING_UNREGISTER_SESSION', sessionId: 's1' }])
	})

	it('throws when no Service Worker API exists', async () => {
		vi.resetModules()
		vi.stubGlobal('navigator', {})
		const { ensureRuntimeWorker } = await import('../../src/elpx/service-worker-client')

		await expect(ensureRuntimeWorker()).rejects.toThrow('not available')
	})
})
