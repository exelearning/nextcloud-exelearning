/**
 * Reads an `.elpx` (ZIP) archive in the browser using fflate. The output is
 * keyed by normalized entry path; binary entries stay as `Uint8Array` so the
 * Service Worker can hand them back as bytes without re-decoding.
 *
 * Limits are enforced both on the number of entries and on the total
 * decompressed size to prevent ZIP bombs.
 */

import { unzipSync } from 'fflate'
import { normalizeEntryPath } from './paths'

export interface ZipReadLimits {
	maxEntries: number
	maxUncompressedBytes: number
	maxCompressedBytes: number
}

export const DEFAULT_LIMITS: ZipReadLimits = {
	maxEntries: 5000,
	maxUncompressedBytes: 500 * 1024 * 1024,
	maxCompressedBytes: 250 * 1024 * 1024,
}

export interface ZipReadResult {
	entries: Map<string, Uint8Array>
	totalUncompressedBytes: number
}

export class ZipReadError extends Error {
	constructor(message: string, public readonly code: ZipReadErrorCode) {
		super(message)
		this.name = 'ZipReadError'
	}
}

export type ZipReadErrorCode =
	| 'NOT_A_ZIP'
	| 'TOO_MANY_ENTRIES'
	| 'PACKAGE_TOO_LARGE'
	| 'UNSAFE_ENTRY'
	| 'CORRUPT'

const ZIP_LOCAL_SIGNATURE = 0x04034b50

/**
 * Cheap sanity check on the file header before handing the buffer to fflate.
 * Empty .elpx files and accidental text uploads (e.g. `.html` renamed) fail
 * here with a clear error instead of hitting the inflate path.
 */
export function looksLikeZip(buffer: ArrayBuffer): boolean {
	if (buffer.byteLength < 4) {
		return false
	}
	const view = new DataView(buffer)
	return view.getUint32(0, true) === ZIP_LOCAL_SIGNATURE
}

export async function readPackage(
	buffer: ArrayBuffer,
	limits: ZipReadLimits = DEFAULT_LIMITS,
): Promise<ZipReadResult> {
	if (buffer.byteLength > limits.maxCompressedBytes) {
		throw new ZipReadError(
			`Package exceeds compressed-size limit (${buffer.byteLength} > ${limits.maxCompressedBytes})`,
			'PACKAGE_TOO_LARGE',
		)
	}
	if (!looksLikeZip(buffer)) {
		throw new ZipReadError('Not a ZIP archive', 'NOT_A_ZIP')
	}

	const bytes = new Uint8Array(buffer)
	// fflate's async `unzip` paralellises decompression with Web Workers
	// spawned from `blob:` URLs, which Nextcloud's CSP (`worker-src 'self'`)
	// blocks. `unzipSync` runs on the main thread; that is acceptable for
	// the package sizes the viewer accepts (250 MB compressed max), and we
	// already cap entry count and uncompressed size below.
	//
	// Yielding to the event loop once before the synchronous decode keeps
	// the loading spinner painted while we work.
	await new Promise((resolve) => setTimeout(resolve, 0))
	let decoded: Record<string, Uint8Array>
	try {
		decoded = unzipSync(bytes)
	} catch (error) {
		throw new ZipReadError(
			error instanceof Error ? (error.message || 'ZIP decode failed') : 'ZIP decode failed',
			'CORRUPT',
		)
	}

	const entries = new Map<string, Uint8Array>()
	let totalUncompressed = 0
	let count = 0
	for (const [rawName, data] of Object.entries(decoded)) {
		// fflate exposes folder entries as zero-length values with a trailing
		// slash. We skip them — only file entries are useful for serving.
		if (rawName.endsWith('/')) {
			continue
		}
		count += 1
		if (count > limits.maxEntries) {
			throw new ZipReadError(`Package has more than ${limits.maxEntries} entries`, 'TOO_MANY_ENTRIES')
		}
		const normalized = normalizeEntryPath(rawName)
		if (normalized === null) {
			throw new ZipReadError(`Unsafe entry path: ${rawName}`, 'UNSAFE_ENTRY')
		}
		totalUncompressed += data.byteLength
		if (totalUncompressed > limits.maxUncompressedBytes) {
			throw new ZipReadError(
				`Decompressed package exceeds limit (${totalUncompressed} > ${limits.maxUncompressedBytes})`,
				'PACKAGE_TOO_LARGE',
			)
		}
		entries.set(normalized, data)
	}

	return { entries, totalUncompressedBytes: totalUncompressed }
}
