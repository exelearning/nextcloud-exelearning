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
 * Built against `@nextcloud/files@^4` (NC 33+ scope `v4_0`). NC 31-32 also
 * accept v4-built actions during the upstream transition.
 */

import {
	DefaultType,
	type IFileAction,
	type Node,
	registerFileAction,
} from '@nextcloud/files'
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
 * Adapter that lets {@link isElpxFile} (which works on a structural shape)
 * accept a `@nextcloud/files` `Node` directly.
 * @param node Files-app node passed by the FileAction `enabled` callback.
 */
function isElpxNode(node: Node): boolean {
	return isElpxFile(node as unknown as NodeShape)
}

// Default action — clicking a .elpx in Files opens our view page, which
// renders the package's internal HTML and exposes an Edit button.
const viewAction: IFileAction = {
	id: 'exelearning-view',
	displayName: () => t(APP_ID, 'Open eXeLearning preview'),
	iconSvgInline: () => eyeIconSvgInline(),
	default: DefaultType.DEFAULT,
	enabled: ({ nodes }) => nodes.length === 1 && isElpxNode(nodes[0] as Node),
	async exec({ nodes }) {
		const file = nodes[0]
		const fileId = (file as unknown as NodeShape).fileid ?? file.fileid
		const url = generateUrl('/apps/exelearning/view?fileId={fileId}', {
			fileId: String(fileId ?? ''),
		})
		window.open(url, '_self')
		return null
	},
}

// Kebab item — jump straight to the editor without going through the
// preview.
const editAction: IFileAction = {
	id: 'exelearning-edit',
	displayName: () => t(APP_ID, 'Edit with eXeLearning'),
	iconSvgInline: () => pencilIconSvgInline(),
	enabled: ({ nodes }) => nodes.length === 1 && isElpxNode(nodes[0] as Node),
	async exec({ nodes }) {
		const file = nodes[0]
		const fileId = (file as unknown as NodeShape).fileid ?? file.fileid
		const url = generateUrl('/apps/exelearning/view?fileId={fileId}&mode=editor', {
			fileId: String(fileId ?? ''),
		})
		window.open(url, '_self')
		return null
	},
}

// Lets users open a non-.elp(x) zip with the eXeLearning viewer (e.g.
// after `unzip` produced a folder they want to repack and previewed,
// or when a `.elpx` was renamed to `.zip`). Hidden for files we already
// claim by default and for things that aren't zips at all so the menu
// doesn't get crowded.
const openAsExeLearningAction: IFileAction = {
	id: 'exelearning-open-as',
	displayName: () => t(APP_ID, 'Open as eXeLearning'),
	iconSvgInline: () => eyeIconSvgInline(),
	enabled: ({ nodes }) => {
		if (nodes.length !== 1) return false
		const node = nodes[0] as Node
		const shape = node as unknown as NodeShape
		// Skip if our default action already handles it.
		if (isElpxNode(node)) return false
		const name = (shape.basename ?? shape.displayName ?? '').toLowerCase()
		const mime = (shape.mime ?? '').toLowerCase()
		return name.endsWith('.zip') || mime === 'application/zip'
	},
	async exec({ nodes }) {
		const file = nodes[0]
		const fileId = (file as unknown as NodeShape).fileid ?? file.fileid
		const url = generateUrl('/apps/exelearning/view?fileId={fileId}', {
			fileId: String(fileId ?? ''),
		})
		window.open(url, '_self')
		return null
	},
}

const downloadAction: IFileAction = {
	id: 'exelearning-download',
	displayName: () => t(APP_ID, 'Download .elpx'),
	iconSvgInline: () => downloadIconSvgInline(),
	enabled: ({ nodes }) => {
		if (nodes.length !== 1) return false
		const node = nodes[0] as unknown as NodeShape
		return hasElpxExtension(node.basename ?? '')
	},
	async exec({ nodes }) {
		const node = nodes[0] as unknown as NodeShape
		const a = document.createElement('a')
		a.href = node.source ?? '#'
		a.download = node.basename ?? 'package.elpx'
		document.body.appendChild(a)
		a.click()
		a.remove()
		return null
	},
}

/**
 *
 */
export function registerFileActions(): void {
	registerFileAction(viewAction) // default — opens /apps/exelearning/view
	registerFileAction(editAction) // kebab  — opens /apps/exelearning/editor
	registerFileAction(downloadAction) // kebab  — native download
	registerFileAction(openAsExeLearningAction) // kebab on plain .zip
}
