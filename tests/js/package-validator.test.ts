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
		expect(shape.legacyMarker).toBeNull()
	})

	it('falls back to index.htm if html is missing', () => {
		const shape = inspectPackage(pkg('index.htm'))
		expect(shape.indexEntry).toBe('index.htm')
	})

	it('returns null when no index is present', () => {
		const shape = inspectPackage(pkg('content.xml'))
		expect(shape.indexEntry).toBeNull()
	})

	it('flags root-level contentv3.xml as a legacy marker', () => {
		const shape = inspectPackage(pkg(
			'content.data',
			'contentv3.xml',
			'content.xsd',
			'image.png',
		))
		expect(shape.legacyMarker).toBe('contentv3.xml')
	})

	it('also flags contentv2 markers', () => {
		const shape = inspectPackage(pkg('contentv2.xml'))
		expect(shape.legacyMarker).toBe('contentv2.xml')
	})

	it('does not mistake content/foo.xml for a legacy marker', () => {
		const shape = inspectPackage(pkg('content/page.xml', 'content.xml'))
		expect(shape.legacyMarker).toBeNull()
	})
})

describe('validatePackage', () => {
	it('accepts a package with index.html', () => {
		const result = validatePackage(pkg('index.html', 'html/page.html'))
		expect(result.valid).toBe(true)
		expect(result.legacy).toBe(false)
		expect(result.error).toBeUndefined()
	})

	it('reports legacy=true when contentv3.xml is present and there is no index', () => {
		const result = validatePackage(pkg('content.data', 'contentv3.xml'))
		expect(result.valid).toBe(false)
		expect(result.legacy).toBe(true)
		expect(result.error).toContain('older version')
	})

	it('reports legacy=true for `.elp` files even without a contentv marker', () => {
		const result = validatePackage(pkg('content.data', 'image.png'), 'old-modelocrea.elp')
		expect(result.valid).toBe(false)
		expect(result.legacy).toBe(true)
	})

	it('reports legacy=false when neither marker nor extension matches', () => {
		const result = validatePackage(pkg('content.xml'), 'random.zip')
		expect(result.valid).toBe(false)
		expect(result.legacy).toBe(false)
		expect(result.error).toContain('index.html')
	})
})
