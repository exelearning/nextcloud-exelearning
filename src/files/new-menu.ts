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

// eXeLearning brand mark (the teal twisted-X). Pulled from the upstream
// editor's `style/workarea/images/exelearning.svg`; we drop the
// wordmark and keep only the iconic glyph since at menu-icon sizes a
// 16-pixel rendering of the "eXeLearning" text would be illegible.
// Brand teal `#26ddc7` is hard-coded so the entry stays visually
// branded across Nextcloud's light + dark themes (the menu wraps the
// icon in a 20x20 slot that already inherits text colour for spacing,
// not for tinting our mark).
/**
 *
 */
function exeLearningIconSvgInline(): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 305 230" fill="#26ddc7"><path d="m102.249.584028c9.46 0 19.566 3.440102 30.316 10.320372 10.536 6.6652 25.049 18.4907 43.539 35.4763 31.821-26.661 48.795-32.6665 65.135-32.6665 10.536 0 19.566 2.9026 27.091 8.7078 13.863 10.6007 16.175 34.8103 6.463 50.7272-7.74 12.6855-17.846 26.3385-30.316 40.9588 28.381 32.251 44.233 55.457 44.233 71.368 0 11.395-3.548 20.103-10.643 26.123-7.31 6.02-16.448 9.03-27.413 9.03-16.556 0-42.514-12.562-74.55-39.438-18.275 16.125-32.681 27.306-43.216 33.541-10.751 6.235-20.964 9.353-30.639 9.353-12.9004 0-22.8983-4.193-29.9935-12.578-7.3103-8.601-10.9654-18.706-10.9654-30.316 0-7.526 1.075-14.083 3.2251-19.674 2.1501-5.59 6.1277-11.825 11.9329-18.705 5.8052-7.096 15.0506-16.878 27.7359-29.349-12.2553-12.47-21.2856-22.4682-27.0909-29.9934-6.0202-7.7403-10.1053-14.5131-12.2554-20.3183-2.3651-5.8052-3.5476-12.2554-3.5476-19.3507 0-7.5253 1.6125-14.513 4.8376-20.9632 3.2252-6.6653 7.9553-12.0405 14.1906-16.12565 6.2352-4.08515 13.5455-6.127721 21.9307-6.127722z"/></svg>'
}

// `OC-FileId` comes back as e.g. "00000123ocsomething" — the leading
// digits are the numeric fileId. Match @nextcloud/files' own parsing.
/**
 * @param raw Raw value of the `OC-FileId` response header.
 */
function parseOcFileId(raw: string): number | null {
	const match = /^(\d+)/.exec(raw)
	if (!match) return null
	const value = Number.parseInt(match[1], 10)
	return Number.isFinite(value) && value > 0 ? value : null
}

/**
 * @param folder  Target Files-app folder where the new resource should land.
 * @param content Existing entries in that folder (used to derive a unique filename).
 */
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

/**
 *
 */
export function registerNewMenuEntry(): void {
	addNewFileMenuEntry(entry)
}
