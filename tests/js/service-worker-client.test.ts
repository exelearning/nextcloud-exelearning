import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))

/** Fake ServiceWorker whose state the test drives. */
function fakeWorker(state: ServiceWorkerState) {
	const worker = new EventTarget() as ServiceWorker & { state: ServiceWorkerState }
	worker.state = state
	return worker
}

async function loadClient(installing: ServiceWorker) {
	vi.resetModules()
	const registration = { installing, waiting: null, active: null }
	vi.stubGlobal('navigator', {
		serviceWorker: { controller: null, register: vi.fn().mockResolvedValue(registration) },
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
