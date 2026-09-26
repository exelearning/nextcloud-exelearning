import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { EditorFrame } from '../../src/editor/editor-frame'

let container: HTMLElement
let frame: EditorFrame

function iframeWindow(): Window {
	const iframe = container.querySelector('iframe')
	if (!iframe?.contentWindow) throw new Error('iframe not attached')
	return iframe.contentWindow
}

/** Delivers a message to the page as if the editor iframe had posted it. */
function fromEditor(data: unknown, source: Window | null = iframeWindow()): void {
	window.dispatchEvent(new MessageEvent('message', { data, source }))
}

/** Captures what the page posts into the editor iframe. */
function spyOnPosts() {
	return vi.spyOn(iframeWindow(), 'postMessage').mockImplementation(() => undefined)
}

async function readyFrame(): Promise<void> {
	const loading = frame.load()
	fromEditor({ type: 'EXELEARNING_READY' })
	await loading
}

beforeEach(() => {
	container = document.createElement('div')
	document.body.appendChild(container)
	frame = new EditorFrame(container, { editorIframeUrl: 'about:blank' })
})

afterEach(() => {
	frame.destroy()
	container.remove()
	vi.useRealTimers()
})

describe('EditorFrame', () => {
	it('refuses to talk to the editor before it reports ready', async () => {
		await expect(frame.requestSave()).rejects.toThrow('not ready')
	})

	it('ignores messages that do not come from its own iframe', async () => {
		vi.useFakeTimers()
		const loading = frame.load()
		fromEditor({ type: 'EXELEARNING_READY' }, window)
		vi.advanceTimersByTime(60_000)

		await expect(loading).rejects.toThrow('Timed out waiting for EXELEARNING_READY')
	})

	it('resolves a save only with the reply to its own request id', async () => {
		await readyFrame()
		const posts = spyOnPosts()

		const saving = frame.requestSave()
		const requestId = (posts.mock.calls[0][0] as { requestId: string }).requestId
		fromEditor({ type: 'SAVE_FILE', requestId: 'someone-else', bytes: new Uint8Array([9]) })
		fromEditor({ type: 'SAVE_FILE', requestId, bytes: new Uint8Array([1, 2]), filename: 'a.elpx' })

		const saved = await saving
		expect(new Uint8Array(saved.bytes)).toEqual(new Uint8Array([1, 2]))
		expect(saved.filename).toBe('a.elpx')
	})

	it('rejects a save when the editor reports an error', async () => {
		await readyFrame()
		const posts = spyOnPosts()

		const saving = frame.requestSave()
		const requestId = (posts.mock.calls[0][0] as { requestId: string }).requestId
		fromEditor({ type: 'REQUEST_SAVE_ERROR', requestId, error: 'disk full' })

		await expect(saving).rejects.toThrow('disk full')
	})

	it('transfers the package bytes when opening a file', async () => {
		await readyFrame()
		const posts = spyOnPosts()
		const bytes = new ArrayBuffer(4)

		const opening = frame.openFile({ bytes, filename: 'lesson.elpx' })
		const [message, , transfer] = posts.mock.calls[0] as unknown as [{ requestId: string }, string, Transferable[]]
		expect(transfer).toEqual([bytes])
		fromEditor({ type: 'OPEN_FILE_SUCCESS', requestId: message.requestId })

		await expect(opening).resolves.toBeUndefined()
	})

	it('times out a save the editor never answers', async () => {
		await readyFrame()
		spyOnPosts()
		vi.useFakeTimers()

		const saving = frame.requestSave()
		vi.advanceTimersByTime(60_000)

		await expect(saving).rejects.toThrow('Timed out waiting for the eXeLearning editor')
	})

	it('reports an error type without a message and a detached iframe', async () => {
		await readyFrame()
		const posts = spyOnPosts()

		const opening = frame.openFile({ bytes: new ArrayBuffer(1), filename: 'x.elpx' })
		const requestId = (posts.mock.calls[0][0] as { requestId: string }).requestId
		fromEditor({ type: 'OPEN_FILE_ERROR', requestId })
		await expect(opening).rejects.toThrow('OPEN_FILE_ERROR')

		container.querySelector('iframe')?.remove()
		await expect(frame.requestSave()).rejects.toThrow('not available')
	})

	it('forwards unsolicited editor messages to subscribers until they unsubscribe', () => {
		const seen: string[] = []
		const unsubscribe = frame.onMessage((message) => seen.push(message.type))

		fromEditor({ type: 'REQUEST_SAVE' })
		unsubscribe()
		fromEditor({ type: 'REQUEST_SAVE' })
		fromEditor('not an editor message')

		expect(seen).toEqual(['REQUEST_SAVE'])
	})
})
