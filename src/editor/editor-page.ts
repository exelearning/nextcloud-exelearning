/**
 * Editor-page orchestrator. Loaded by `EditorController` only on the
 * `/apps/exelearning/editor` route, never on the Files index. Boots an
 * iframe-hosted eXeLearning editor with a file fetched from Nextcloud and
 * writes saves back to the same file with an `If-Match` ETag for conflict
 * detection.
 */

// Same publicPath fix as main.ts — installs that put apps under
// /custom_apps/<id>/ would otherwise 404 on any webpack-emitted chunk.
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

import { loadElpx } from '../elpx/elpx-loader'
import { EditorFrame } from './editor-frame'

declare let __webpack_public_path__: string
declare global {
	interface Window {
		OC?: { appswebroots?: Record<string, string> }
	}
}
if (typeof window !== 'undefined' && window.OC?.appswebroots?.exelearning) {
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	__webpack_public_path__ = `${window.OC.appswebroots.exelearning}/js/`
}

interface InitialFile {
	id: number
	name: string
	path: string
	mtime: number
	etag: string
	writable: boolean
}

/**
 *
 */
async function boot(): Promise<void> {
	const root = document.getElementById('exelearning-editor-root')
	if (!root) return

	const editorAvailable = safeLoad('editorAvailable', false)
	if (!editorAvailable) {
		renderMessage(root, t('exelearning', 'The eXeLearning static editor is not installed. Run `make download-editor` in the app directory.'))
		return
	}

	const file = safeLoad<InitialFile | null>('file', null)
	if (!file) {
		renderMessage(root, t('exelearning', 'No file selected.'))
		return
	}

	// Build the iframe URL client-side so it carries the live webroot. The
	// server-rendered initial-state value is computed with an empty webroot when
	// Nextcloud runs under a sub-path (e.g. the browser Playground), so using it
	// as the iframe src would escape the scope and 404. generateUrl() is correct
	// in both a normal install and under a scoped path.
	const editorIframeUrl = generateUrl('/apps/exelearning/editor/iframe')

	const frame = new EditorFrame(root, { editorIframeUrl })

	try {
		await frame.load()
		const loaded = await loadElpx({ fileId: file.id })
		await frame.openFile({ bytes: loaded.bytes, filename: loaded.filename || file.name })

		window.addEventListener('beforeunload', () => frame.destroy())

		frame.onMessage(async (message) => {
			if (message.type !== 'SAVE_FILE') return
			try {
				const saved = await frame.requestSave()
				const result = await uploadToNextcloud(file, saved.bytes, file.etag)
				// EditorController migrates `.elp` → `.elpx` on first save;
				// reflect the new name locally so the next save sends the
				// updated `file.name` and the document title stops lying.
				if (result.name && result.name !== file.name) {
					file.name = result.name
					document.title = result.name
				}
				if (result.etag) {
					file.etag = result.etag
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[exelearning] Save failed:', error)
			}
		})
	} catch (error) {
		const detail = error instanceof Error ? error.message : String(error)
		renderMessage(root, `${t('exelearning', 'Failed to load the eXeLearning editor.')} — ${detail}`)
	}
}

/**
 * Wraps `loadState` so a missing or malformed initial-state entry falls back
 * to the supplied default instead of throwing during page boot.
 * @param key The initial-state key registered server-side.
 * @param fallback Value to return when the key is missing or invalid.
 */
function safeLoad<T>(key: string, fallback: T): T {
	try {
		return (loadState('exelearning', key, fallback) as T) ?? fallback
	} catch {
		return fallback
	}
}

/**
 * Replaces the target element's content with a single status paragraph.
 * Used to surface boot-time errors (missing editor, missing file, …)
 * without dragging in a Vue runtime for a single string.
 * @param target Element whose content is replaced.
 * @param text Localised message to display.
 */
function renderMessage(target: HTMLElement, text: string): void {
	target.innerHTML = ''
	const p = document.createElement('p')
	p.className = 'exelearning-editor__message'
	p.textContent = text
	target.appendChild(p)
}

/**
 * POSTs the editor's save bytes back to `EditorController::save`. The
 * `If-Match` header carries the original ETag so concurrent edits in
 * another tab fail loudly with a 412 instead of overwriting silently.
 * @param file Metadata for the file being saved (id and display name).
 * @param bytes Raw `.elpx` bytes returned by the editor.
 * @param ifMatch ETag captured at load time, sent back as `If-Match`.
 */
interface SaveResult {
	name?: string
	etag?: string
}

/**
 * @param file Metadata for the file being saved (id and display name).
 * @param bytes Raw `.elpx` bytes returned by the editor.
 * @param ifMatch ETag captured at load time, sent back as `If-Match`.
 */
async function uploadToNextcloud(file: InitialFile, bytes: ArrayBuffer, ifMatch: string): Promise<SaveResult> {
	const url = generateUrl('/apps/exelearning/editor/save')
	const form = new FormData()
	form.append('fileId', String(file.id))
	form.append('package', new Blob([bytes], { type: 'application/vnd.exelearning.elpx' }), file.name)
	const response = await axios.post(url, form, {
		headers: {
			'If-Match': ifMatch,
		},
	})
	const data = response.data as SaveResult | undefined
	return data ?? {}
}

void boot()
