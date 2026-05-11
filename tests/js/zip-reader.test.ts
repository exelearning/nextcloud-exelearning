import { describe, expect, it } from 'vitest'
import { zipSync, strToU8 } from 'fflate'
import { DEFAULT_LIMITS, looksLikeZip, readPackage, ZipReadError } from '../../src/elpx/zip-reader'

function buildPackage(entries: Record<string, Uint8Array | string>): ArrayBuffer {
	const dict: Record<string, Uint8Array> = {}
	for (const [name, value] of Object.entries(entries)) {
		dict[name] = typeof value === 'string' ? strToU8(value) : value
	}
	const bytes = zipSync(dict)
	const sliced = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)
	return sliced as ArrayBuffer
}

describe('looksLikeZip', () => {
	it('detects the PKZIP local-file signature', () => {
		const buffer = buildPackage({ 'index.html': '<html></html>' })
		expect(looksLikeZip(buffer)).toBe(true)
	})

	it('rejects non-zip data', () => {
		const buffer = new TextEncoder().encode('not a zip').buffer
		expect(looksLikeZip(buffer)).toBe(false)
	})

	it('rejects tiny buffers', () => {
		expect(looksLikeZip(new ArrayBuffer(2))).toBe(false)
	})
})

describe('readPackage', () => {
	it('reads entries and preserves binary bytes', async () => {
		const buffer = buildPackage({
			'index.html': '<html></html>',
			'html/page.html': '<p>hi</p>',
			'images/blob.bin': new Uint8Array([1, 2, 3, 4]),
		})
		const result = await readPackage(buffer)
		expect(result.entries.size).toBe(3)
		expect(new TextDecoder().decode(result.entries.get('index.html')!)).toBe('<html></html>')
		expect(Array.from(result.entries.get('images/blob.bin')!)).toEqual([1, 2, 3, 4])
	})

	it('throws NOT_A_ZIP for non-zip input', async () => {
		const buffer = new TextEncoder().encode('not zip').buffer
		await expect(readPackage(buffer)).rejects.toBeInstanceOf(ZipReadError)
	})

	it('rejects entries with parent traversal', async () => {
		const buffer = buildPackage({ '../escape.html': '<html></html>' })
		try {
			await readPackage(buffer)
			throw new Error('expected to throw')
		} catch (error) {
			expect(error).toBeInstanceOf(ZipReadError)
			expect((error as ZipReadError).code).toBe('UNSAFE_ENTRY')
		}
	})

	it('enforces a max-entries limit', async () => {
		const dict: Record<string, string> = {}
		for (let i = 0; i < 10; i++) dict[`file-${i}.txt`] = String(i)
		const buffer = buildPackage(dict)
		await expect(
			readPackage(buffer, { ...DEFAULT_LIMITS, maxEntries: 3 }),
		).rejects.toMatchObject({ code: 'TOO_MANY_ENTRIES' })
	})

	it('enforces a max-uncompressed-size limit', async () => {
		const big = new Uint8Array(1024)
		const buffer = buildPackage({ 'big.bin': big })
		await expect(
			readPackage(buffer, { ...DEFAULT_LIMITS, maxUncompressedBytes: 512 }),
		).rejects.toMatchObject({ code: 'PACKAGE_TOO_LARGE' })
	})
})
