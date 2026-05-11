/**
 * Loads the bundled static eXeLearning editor inside an iframe and exposes a
 * narrow async API for the page-level orchestrator.
 *
 * Earlier versions injected the editor HTML via `iframe.srcdoc`, but
 * Nextcloud's parent-page CSP (`base-uri 'none'`, `script-src strict-dynamic`,
 * nonces) cascades into srcdoc iframes and blocks every inline script and
 * `<base>` the editor needs. We now load the editor through a Nextcloud
 * route ({@link EditorController::iframe}) which serves the patched HTML
 * with its own permissive CSP. The iframe gets a real document URL of its
 * own, no cascaded restrictions.
 */

import {
	isEditorMessage,
	normalizeBytes,
	type EditorMessage,
	type OpenFileRequestData,
	type SaveFileResponse,
} from './editor-messages'

type MessageHandler = (message: EditorMessage) => void

export interface EditorFrameOptions {
	editorIframeUrl: string
}

export interface OpenFileOptions {
	bytes: ArrayBuffer
	filename: string
}

export interface SavedFile {
	bytes: ArrayBuffer
	filename?: string
}

export class EditorFrame {

	private readonly iframe: HTMLIFrameElement
	private readonly handlers = new Set<MessageHandler>()
	private readonly options: EditorFrameOptions
	private requestCounter = 0
	private ready = false

	constructor(container: HTMLElement, options: EditorFrameOptions) {
		this.options = options
		this.iframe = document.createElement('iframe')
		this.iframe.className = 'exelearning-editor__frame'
		this.iframe.title = 'eXeLearning editor'
		this.iframe.setAttribute('allow', 'clipboard-read; clipboard-write')
		this.iframe.style.width = '100%'
		this.iframe.style.height = '100%'
		this.iframe.style.border = '0'
		container.replaceChildren(this.iframe)
		window.addEventListener('message', this.handleMessage)
	}

	async load(): Promise<void> {
		const ready = this.waitForType('EXELEARNING_READY', 60_000)
		this.iframe.src = this.options.editorIframeUrl
		await ready
		this.ready = true
	}

	async openFile(file: OpenFileOptions): Promise<void> {
		this.ensureReady()
		const requestId = this.nextRequestId('open')
		const success = this.waitForReply(['OPEN_FILE_SUCCESS'], ['OPEN_FILE_ERROR'], requestId, 60_000)
		this.post(
			{
				type: 'OPEN_FILE',
				requestId,
				data: { bytes: file.bytes, filename: file.filename } satisfies OpenFileRequestData,
			},
			[file.bytes],
		)
		await success
	}

	async requestSave(): Promise<SavedFile> {
		this.ensureReady()
		const requestId = this.nextRequestId('save')
		const reply = this.waitForReply(['SAVE_FILE'], ['REQUEST_SAVE_ERROR'], requestId, 60_000)
		this.post({ type: 'REQUEST_SAVE', requestId })
		const message = (await reply) as unknown as SaveFileResponse
		const result: SavedFile = { bytes: normalizeBytes(message.bytes) }
		if (typeof message.filename === 'string') {
			result.filename = message.filename
		}
		return result
	}

	onMessage(handler: MessageHandler): () => void {
		this.handlers.add(handler)
		return () => {
			this.handlers.delete(handler)
		}
	}

	destroy(): void {
		window.removeEventListener('message', this.handleMessage)
		this.handlers.clear()
		this.iframe.remove()
	}

	private ensureReady(): void {
		if (!this.ready) {
			throw new Error('The eXeLearning editor is not ready yet.')
		}
	}

	private nextRequestId(prefix: string): string {
		this.requestCounter += 1
		return `nextcloud-exelearning-${prefix}-${this.requestCounter}`
	}

	private post(message: EditorMessage, transfer: Transferable[] = []): void {
		const target = this.iframe.contentWindow
		if (!target) {
			throw new Error('The editor iframe is not available.')
		}
		// The iframe is same-origin; '*' is still safe and matches the
		// upstream EmbeddingBridge examples.
		target.postMessage(message, '*', transfer)
	}

	private waitForType(type: string, timeoutMs: number): Promise<EditorMessage> {
		return new Promise((resolve, reject) => {
			const timeout = window.setTimeout(() => {
				unsubscribe()
				reject(new Error(`Timed out waiting for ${type} from the eXeLearning editor.`))
			}, timeoutMs)
			const unsubscribe = this.onMessage((message) => {
				if (message.type === type) {
					window.clearTimeout(timeout)
					unsubscribe()
					resolve(message)
				}
			})
		})
	}

	private waitForReply(
		successTypes: readonly string[],
		errorTypes: readonly string[],
		requestId: string,
		timeoutMs: number,
	): Promise<EditorMessage> {
		return new Promise((resolve, reject) => {
			const timeout = window.setTimeout(() => {
				unsubscribe()
				reject(new Error(`Timed out waiting for the eXeLearning editor to respond to ${requestId}.`))
			}, timeoutMs)
			const unsubscribe = this.onMessage((message) => {
				if (message.requestId !== requestId) return
				if (successTypes.includes(message.type)) {
					window.clearTimeout(timeout)
					unsubscribe()
					resolve(message)
					return
				}
				if (errorTypes.includes(message.type)) {
					window.clearTimeout(timeout)
					unsubscribe()
					const errorMessage = typeof message.error === 'string' ? message.error : message.type
					reject(new Error(`The eXeLearning editor reported an error: ${errorMessage}`))
				}
			})
		})
	}

	private readonly handleMessage = (event: MessageEvent<unknown>): void => {
		if (event.source !== this.iframe.contentWindow || !isEditorMessage(event.data)) {
			return
		}
		for (const handler of this.handlers) {
			handler(event.data)
		}
	}

}
