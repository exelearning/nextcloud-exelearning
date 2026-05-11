/**
 * Loads `.elpx` bytes for the current Nextcloud user. The backend
 * `PackageController` enforces permission and MIME checks; this module is
 * just the HTTP client.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export interface LoadElpxOptions {
	fileId?: number
	path?: string
	signal?: AbortSignal
}

export interface LoadedElpx {
	bytes: ArrayBuffer
	filename: string
	etag?: string
	contentLength?: number
}

/**
 *
 * @param options
 */
export async function loadElpx(options: LoadElpxOptions): Promise<LoadedElpx> {
	if (options.fileId === undefined && (options.path === undefined || options.path === '')) {
		throw new Error('Either fileId or path is required to load an .elpx package.')
	}

	const url = options.fileId !== undefined
		? generateUrl('/apps/exelearning/package/by-file-id/{fileId}', { fileId: options.fileId })
		: generateUrl('/apps/exelearning/package/by-path')

	const response = await axios.get<ArrayBuffer>(url, {
		responseType: 'arraybuffer',
		...(options.path !== undefined ? { params: { path: options.path } } : {}),
		...(options.signal !== undefined ? { signal: options.signal } : {}),
	})

	const disposition = response.headers['content-disposition']
	const filename = parseFilename(typeof disposition === 'string' ? disposition : null)
		?? deriveFilename(options.path, options.fileId)

	const etagHeader = response.headers.etag
	return {
		bytes: response.data,
		filename,
		...(typeof etagHeader === 'string' ? { etag: etagHeader } : {}),
		...(typeof response.data.byteLength === 'number'
			? { contentLength: response.data.byteLength }
			: {}),
	}
}

/**
 *
 * @param header
 */
function parseFilename(header: string | null): string | null {
	if (!header) return null
	const match = header.match(/filename\*=UTF-8''([^;]+)/i) ?? header.match(/filename="?([^";]+)"?/i)
	if (!match) return null
	try {
		return decodeURIComponent(match[1])
	} catch {
		return match[1]
	}
}

/**
 *
 * @param path
 * @param fileId
 */
function deriveFilename(path?: string, fileId?: number): string {
	if (path) {
		const slash = path.lastIndexOf('/')
		return slash >= 0 ? path.slice(slash + 1) : path
	}
	if (fileId !== undefined) {
		return `package-${fileId}.elpx`
	}
	return 'package.elpx'
}
