/**
 * Validates that an extracted archive looks like an eXeLearning project.
 *
 * The viewer only needs `index.html`. Everything else is a soft signal that
 * helps us recognize the package shape and surface clearer errors when the
 * user uploaded a `.zip` that is not an eXeLearning project.
 */

const ROOT_INDEX_CANDIDATES = ['index.html', 'index.htm']
const DIR_HINTS = ['html/', 'content/', 'libs/', 'theme/', 'idevices/']

export interface PackageShape {
	indexEntry: string | null
	hasContentXml: boolean
	hasScreenshot: boolean
	hintCount: number
}

export interface PackageValidation {
	valid: boolean
	shape: PackageShape
	error?: string
}

export function inspectPackage(entries: ReadonlyMap<string, Uint8Array>): PackageShape {
	let indexEntry: string | null = null
	for (const candidate of ROOT_INDEX_CANDIDATES) {
		if (entries.has(candidate)) {
			indexEntry = candidate
			break
		}
	}
	let hintCount = 0
	for (const entry of entries.keys()) {
		for (const dir of DIR_HINTS) {
			if (entry.startsWith(dir)) {
				hintCount += 1
				break
			}
		}
	}
	return {
		indexEntry,
		hasContentXml: entries.has('content.xml'),
		hasScreenshot: entries.has('screenshot.png'),
		hintCount,
	}
}

export function validatePackage(entries: ReadonlyMap<string, Uint8Array>): PackageValidation {
	const shape = inspectPackage(entries)
	if (shape.indexEntry === null) {
		return {
			valid: false,
			shape,
			error: 'The package does not contain an index.html.',
		}
	}
	return { valid: true, shape }
}
