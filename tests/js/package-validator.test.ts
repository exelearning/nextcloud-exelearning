import { describe, expect, it } from 'vitest'
import { inspectPackage, validatePackage } from '../../src/elpx/package-validator'

function pkg(...entries: string[]): Map<string, Uint8Array> {
	const map = new Map<string, Uint8Array>()
	for (const entry of entries) {
		map.set(entry, new Uint8Array(0))
	}
	return map
}

describe('inspectPackage', () => {
	it('detects index.html and the standard hint directories', () => {
		const shape = inspectPackage(pkg(
			'index.html',
			'content.xml',
			'screenshot.png',
			'html/page.html',
			'libs/jquery.js',
			'theme/style.css',
			'idevices/widget.js',
		))
		expect(shape.indexEntry).toBe('index.html')
		expect(shape.hasContentXml).toBe(true)
		expect(shape.hasScreenshot).toBe(true)
		expect(shape.hintCount).toBe(4)
	})

	it('falls back to index.htm if html is missing', () => {
		const shape = inspectPackage(pkg('index.htm'))
		expect(shape.indexEntry).toBe('index.htm')
	})

	it('returns null when no index is present', () => {
		const shape = inspectPackage(pkg('content.xml'))
		expect(shape.indexEntry).toBeNull()
	})
})

describe('validatePackage', () => {
	it('accepts a package with index.html', () => {
		const result = validatePackage(pkg('index.html', 'html/page.html'))
		expect(result.valid).toBe(true)
		expect(result.error).toBeUndefined()
	})

	it('rejects packages without an index page', () => {
		const result = validatePackage(pkg('content.xml'))
		expect(result.valid).toBe(false)
		expect(result.error).toContain('index.html')
	})
})
