/**
 * Registers a "+ New" entry in the Nextcloud Files app that creates a
 * brand-new `.elpx` resource in the current folder.
 *
 * The blank package itself is served by `TemplateController::blank()` at
 * `/apps/exelearning/template/blank`. We fetch it, choose a non-clashing
 * filename in the target folder, PUT it via the user's WebDAV root, and
 * then jump to the editor view so the user can start authoring
 * immediately (matches the gdrive-exelearning new-resource flow).
 */

import axios from '@nextcloud/axios'
import {
	addNewFileMenuEntry,
	getUniqueName,
	Permission,
	type IFolder,
	type INode,
	type NewMenuEntry,
} from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const APP_ID = 'exelearning'

// 16x16 viewBox matches img/app.svg so the menu icon visually aligns
// with the icon Nextcloud already uses for the app entry.
function exeLearningIconSvgInline(): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"><path d="M2 1.5A1.5 1.5 0 0 1 3.5 0h7L14 3.5v11a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 14.5v-13zM10 1v3h3l-3-3zM4.5 7h7v1h-7V7zm0 2.5h7v1h-7v-1zm0 2.5h4v1h-4v-1z"/></svg>'
}

// `OC-FileId` comes back as e.g. "00000123ocsomething" — the leading
// digits are the numeric fileId. Match @nextcloud/files' own parsing.
function parseOcFileId(raw: string): number | null {
	const match = /^(\d+)/.exec(raw)
	if (!match) return null
	const value = Number.parseInt(match[1], 10)
	return Number.isFinite(value) && value > 0 ? value : null
}

async function createNewExeLearningResource(folder: IFolder, content: INode[]): Promise<void> {
	const defaultName = t(APP_ID, 'New eXeLearning resource') + '.elpx'
	const name = getUniqueName(
		defaultName,
		content.map((node) => node.basename),
	)

	const templateUrl = generateUrl('/apps/exelearning/template/blank')
	const templateResponse = await axios.get<ArrayBuffer>(templateUrl, {
		responseType: 'arraybuffer',
	})

	const targetUrl = `${folder.source}/${encodeURIComponent(name)}`
	const putResponse = await axios.put(targetUrl, templateResponse.data, {
		headers: { 'Content-Type': 'application/vnd.exelearning.elpx' },
	})

	const fileIdHeader = (putResponse.headers['oc-fileid']
		?? putResponse.headers['OC-FileId']
		?? '') as string
	const fileId = parseOcFileId(fileIdHeader)
	if (fileId === null) {
		// Couldn't recover the fileId from headers (older NC, proxy strip,
		// etc). Fall back to a Files reload so the new file at least shows
		// up in the list — the user can click it manually.
		window.location.reload()
		return
	}

	const editorUrl = generateUrl(
		'/apps/exelearning/view?fileId={fileId}&mode=editor',
		{ fileId: String(fileId) },
	)
	window.open(editorUrl, '_self')
}

const entry: NewMenuEntry = {
	id: 'exelearning-new-resource',
	displayName: t(APP_ID, 'New eXeLearning resource'),
	iconSvgInline: exeLearningIconSvgInline(),
	enabled: (folder) => Boolean(folder.permissions & Permission.CREATE),
	handler(folder, content) {
		void createNewExeLearningResource(folder, content).catch((error: unknown) => {
			// eslint-disable-next-line no-console
			console.error('[exelearning] failed to create new resource:', error)
			window.alert(t(APP_ID, 'Could not create a new eXeLearning resource. See console for details.'))
		})
	},
}

export function registerNewMenuEntry(): void {
	addNewFileMenuEntry(entry)
}
