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

/**
 *
 */
function appWebRoot(): string {
	// Falls back to the canonical /apps path so unit tests and stripped-down
	// pages without OC.appswebroots still produce a sensible URL.
	return window.OC?.appswebroots?.exelearning ?? '/apps/exelearning'
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

	// Defaults to the route URL; initial state can still override.
	const editorIframeUrl = safeLoad<string>(
		'editorIframeUrl',
		`${appWebRoot()}/editor/iframe`,
	)

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
				await uploadToNextcloud(file, saved.bytes, file.etag)
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
 *
 * @param key
 * @param fallback
 */
function safeLoad<T>(key: string, fallback: T): T {
	try {
		return (loadState('exelearning', key, fallback) as T) ?? fallback
	} catch {
		return fallback
	}
}

/**
 *
 * @param target
 * @param text
 */
function renderMessage(target: HTMLElement, text: string): void {
	target.innerHTML = ''
	const p = document.createElement('p')
	p.className = 'exelearning-editor__message'
	p.textContent = text
	target.appendChild(p)
}

/**
 *
 * @param file
 * @param bytes
 * @param ifMatch
 */
async function uploadToNextcloud(file: InitialFile, bytes: ArrayBuffer, ifMatch: string): Promise<void> {
	const url = generateUrl('/apps/exelearning/editor/save')
	const form = new FormData()
	form.append('fileId', String(file.id))
	form.append('package', new Blob([bytes], { type: 'application/vnd.exelearning.elpx' }), file.name)
	await axios.post(url, form, {
		headers: {
			'If-Match': ifMatch,
		},
	})
}

void boot()
