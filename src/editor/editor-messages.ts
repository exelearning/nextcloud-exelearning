/**
 * eXeLearning embedding postMessage protocol.
 *
 * Mirrors the protocol documented in the upstream eXeLearning repository at
 * `doc/development/embedding.md`. We reuse the same message shape as
 * gdrive-exelearning so the editor's `EmbeddingBridge` does not need to know
 * which host it is running inside.
 */

export type EditorMessageType =
	| 'EXELEARNING_READY'
	| 'OPEN_FILE'
	| 'OPEN_FILE_SUCCESS'
	| 'OPEN_FILE_ERROR'
	| 'DOCUMENT_LOADED'
	| 'REQUEST_SAVE'
	| 'REQUEST_SAVE_ERROR'
	| 'SAVE_FILE'
	| 'CONFIGURE'
	| 'CONFIGURE_SUCCESS'
	| 'EXELEARNING_EVENT'

export interface EditorMessage<TPayload = unknown> {
	type: EditorMessageType | string
	requestId?: string
	data?: TPayload
	[key: string]: unknown
}

export interface OpenFileRequestData {
	bytes: ArrayBuffer
	filename: string
}

export interface SaveFileResponse {
	type: 'SAVE_FILE'
	requestId?: string
	bytes: ArrayBuffer | ArrayBufferView
	filename?: string
	size?: number
}

export function isEditorMessage(value: unknown): value is EditorMessage {
	return (
		typeof value === 'object'
		&& value !== null
		&& 'type' in value
		&& typeof (value as { type: unknown }).type === 'string'
	)
}

export function normalizeBytes(value: unknown): ArrayBuffer {
	if (value instanceof ArrayBuffer) {
		return value
	}
	if (ArrayBuffer.isView(value)) {
		const view = value as ArrayBufferView
		// `.buffer` is typed as `ArrayBuffer | SharedArrayBuffer` since TS 5.7
		// even though we never construct SharedArrayBuffer views; the slice
		// returns a plain ArrayBuffer copy in both cases.
		const sliced = view.buffer.slice(view.byteOffset, view.byteOffset + view.byteLength)
		return sliced as ArrayBuffer
	}
	throw new Error('The editor returned a save payload without binary bytes.')
}
