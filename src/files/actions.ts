/**
 * File actions registered with Nextcloud Files for `.elpx` packages.
 *
 *   - `exelearning-view`  — default action; opens the full-page preview
 *                            (renders the package's index.html with an Edit
 *                            button in the toolbar).
 *   - `exelearning-edit`  — kebab item that jumps straight to the eXeLearning
 *                            editor for the file.
 *   - `exelearning-download` — kebab item that triggers a download via the
 *                            node's WebDAV `source` URL.
 *
 * NB: this app targets Nextcloud 30 which bundles `@nextcloud/files@3.x`.
 * That version exposes `FileAction` as a **class** (no `IFileAction`
 * interface) with callbacks that receive `Node[]` / `Node` directly.
 * Pinning matters: `@nextcloud/files@4.x` uses a different global scope
 * (`window._nc_files_scope.v4_0`) and our actions become invisible to
 * Files when the versions mismatch.
 */

import { DefaultType, FileAction, registerFileAction, type Node } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

import { hasElpxExtension, isElpxFile } from './mime'

const APP_ID = 'exelearning'

interface NodeShape {
	mime?: string
	basename?: string
	displayName?: string
	source?: string
	fileid?: number
	path?: string
	permissions?: number
}

/**
 *
 */
function eyeIconSvgInline(): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z"/></svg>'
}

/**
 *
 */
function pencilIconSvgInline(): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20.71 7.04c.39-.39.39-1.04 0-1.41l-2.34-2.34c-.37-.39-1.02-.39-1.41 0l-1.84 1.83 3.75 3.75M3 17.25V21h3.75L17.81 9.93l-3.75-3.75L3 17.25z"/></svg>'
}

/**
 *
 */
function downloadIconSvgInline(): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/></svg>'
}

/**
 *
 * @param node
 */
function isElpxNode(node: Node): boolean {
	return isElpxFile(node as unknown as NodeShape)
}

// Default action — clicking a .elpx in Files opens our view page, which
// renders the package's internal HTML and exposes an Edit button.
const viewAction = new FileAction({
	id: 'exelearning-view',
	displayName: () => t(APP_ID, 'Open eXeLearning preview'),
	iconSvgInline: () => eyeIconSvgInline(),
	default: DefaultType.DEFAULT,
	enabled: (files: Node[]) => files.length === 1 && isElpxNode(files[0]),
	async exec(file: Node) {
		const fileId = (file as unknown as NodeShape).fileid ?? file.fileid
		const url = generateUrl('/apps/exelearning/view?fileId={fileId}', {
			fileId: String(fileId ?? ''),
		})
		window.open(url, '_self')
		return null
	},
})

// Kebab item — jump straight to the editor without going through the
// preview.
const editAction = new FileAction({
	id: 'exelearning-edit',
	displayName: () => t(APP_ID, 'Edit with eXeLearning'),
	iconSvgInline: () => pencilIconSvgInline(),
	enabled: (files: Node[]) => files.length === 1 && isElpxNode(files[0]),
	async exec(file: Node) {
		const fileId = (file as unknown as NodeShape).fileid ?? file.fileid
		const url = generateUrl('/apps/exelearning/view?fileId={fileId}&mode=editor', {
			fileId: String(fileId ?? ''),
		})
		window.open(url, '_self')
		return null
	},
})

const downloadAction = new FileAction({
	id: 'exelearning-download',
	displayName: () => t(APP_ID, 'Download .elpx'),
	iconSvgInline: () => downloadIconSvgInline(),
	enabled: (files: Node[]) => {
		if (files.length !== 1) return false
		const node = files[0] as unknown as NodeShape
		return hasElpxExtension(node.basename ?? '')
	},
	async exec(file: Node) {
		const node = file as unknown as NodeShape
		const a = document.createElement('a')
		a.href = node.source ?? '#'
		a.download = node.basename ?? 'package.elpx'
		document.body.appendChild(a)
		a.click()
		a.remove()
		return null
	},
})

/**
 *
 */
export function registerFileActions(): void {
	registerFileAction(viewAction) // default — opens /apps/exelearning/view
	registerFileAction(editAction) // kebab  — opens /apps/exelearning/editor
	registerFileAction(downloadAction) // kebab  — native download
}
