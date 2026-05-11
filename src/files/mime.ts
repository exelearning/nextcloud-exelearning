/**
 * MIME helpers used by the file action / viewer registration code. Old `.elpx`
 * files in Nextcloud can be detected as `application/zip` or
 * `application/octet-stream` because the MIME mapping is admin-controlled
 * (see README → MIME mapping). Always check the extension in addition to
 * the MIME so we still recognize legacy uploads.
 */

export const PRIMARY_MIME = 'application/vnd.exelearning.elpx'

export const ELPX_MIME_TYPES = [
	PRIMARY_MIME,
	'application/x-exelearning',
	'application/zip',
	'application/octet-stream',
]

export const ELPX_EXTENSIONS = ['.elpx', '.elp']

/**
 * Case-insensitive check for filenames the app should claim. Used as a
 * fallback when the server-side MIME detection misclassifies an upload.
 * @param name Filename or basename, with extension included.
 */
export function hasElpxExtension(name: string): boolean {
	const lower = name.toLowerCase()
	return ELPX_EXTENSIONS.some((ext) => lower.endsWith(ext))
}

export interface FileLike {
	mime?: string
	name?: string
	basename?: string
	displayName?: string
}

/**
 * Decides whether the given file should trigger this app's actions. Uses
 * the filename when available (the most reliable signal) and only accepts
 * plain `application/zip` when the extension also matches — otherwise
 * every ZIP in the user's Files would gain an "Open with eXeLearning"
 * action.
 * @param file Minimal Files-app shape (mime + name/basename/displayName).
 */
export function isElpxFile(file: FileLike): boolean {
	const name = file.name ?? file.basename ?? file.displayName ?? ''
	if (name && hasElpxExtension(name)) {
		return true
	}
	const mime = file.mime?.toLowerCase()
	if (!mime) return false
	// Plain `application/zip` is too generic to opt in on its own; we require
	// an extension match for those. The vendor MIME is enough on its own.
	return mime === PRIMARY_MIME || mime === 'application/x-exelearning'
}
