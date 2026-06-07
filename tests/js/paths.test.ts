import { describe, expect, it } from 'vitest'
import {
	buildAssetUrl,
	buildRuntimeUrl,
	isExternalUrl,
	normalizeEntryPath,
	parseRuntimeUrl,
	resolveRelativeEntry,
	RUNTIME_PREFIX,
} from '../../src/elpx/paths'

describe('normalizeEntryPath', () => {
	it('returns paths unchanged when they are already canonical', () => {
		expect(normalizeEntryPath('index.html')).toBe('index.html')
		expect(normalizeEntryPath('html/page-1.html')).toBe('html/page-1.html')
	})

	it('strips leading slashes and resolves dots', () => {
		expect(normalizeEntryPath('/html/./page.html')).toBe('html/page.html')
		expect(normalizeEntryPath('content//x.png')).toBe('content/x.png')
	})

	it('rejects parent-traversal paths', () => {
		expect(normalizeEntryPath('../etc/passwd')).toBeNull()
		expect(normalizeEntryPath('html/../../etc')).toBeNull()
	})

	it('rejects empty and NUL-tainted paths', () => {
		expect(normalizeEntryPath('')).toBeNull()
		expect(normalizeEntryPath('a\0b')).toBeNull()
	})

	it('normalizes backslashes to forward slashes', () => {
		expect(normalizeEntryPath('html\\page.html')).toBe('html/page.html')
	})
})

describe('resolveRelativeEntry', () => {
	it('resolves siblings of the base entry', () => {
		expect(resolveRelativeEntry('html/page.html', 'image.png')).toBe('html/image.png')
	})

	it('resolves up-references within the package', () => {
		expect(resolveRelativeEntry('html/sub/page.html', '../image.png')).toBe('html/image.png')
	})

	it('rejects escapes from the package root', () => {
		expect(resolveRelativeEntry('html/page.html', '../../etc/passwd')).toBeNull()
	})

	it('refuses to resolve external URLs', () => {
		expect(resolveRelativeEntry('html/page.html', 'https://example.com/x')).toBeNull()
	})
})

describe('isExternalUrl', () => {
	it('detects schemes and protocol-relative URLs', () => {
		expect(isExternalUrl('http://x')).toBe(true)
		expect(isExternalUrl('https://x')).toBe(true)
		expect(isExternalUrl('//cdn/x')).toBe(true)
		expect(isExternalUrl('mailto:x@y')).toBe(true)
		expect(isExternalUrl('tel:+34')).toBe(true)
	})

	it('keeps relative references and data/blob URIs in-package', () => {
		expect(isExternalUrl('image.png')).toBe(false)
		expect(isExternalUrl('./image.png')).toBe(false)
		expect(isExternalUrl('data:image/png;base64,abc')).toBe(false)
		expect(isExternalUrl('blob:abc')).toBe(false)
	})
})

describe('buildRuntimeUrl / parseRuntimeUrl', () => {
	const base = RUNTIME_PREFIX

	it('builds a runtime URL and parses it back', () => {
		const url = buildRuntimeUrl(base, 'session-1', 'html/page.html')
		expect(url).toBe('/apps/exelearning/runtime/session-1/html/page.html')
		expect(parseRuntimeUrl(url, base)).toEqual({ sessionId: 'session-1', entry: 'html/page.html' })
	})

	it('refuses unsafe entries at build time', () => {
		expect(() => buildRuntimeUrl(base, 's', '../etc')).toThrow()
	})

	it('returns null for URLs outside the runtime base', () => {
		expect(parseRuntimeUrl('/apps/other/x', base)).toBeNull()
	})

	it('round-trips entries with special characters', () => {
		const url = buildRuntimeUrl(base, 'sess', 'content/Página 1.html')
		const parsed = parseRuntimeUrl(url, base)
		expect(parsed?.entry).toBe('content/Página 1.html')
	})

	it('tolerates a trailing slash on the base', () => {
		const url = buildRuntimeUrl(base + '/', 'sess', 'a/b')
		expect(parseRuntimeUrl(url, base)).toEqual({ sessionId: 'sess', entry: 'a/b' })
	})
})

describe('buildAssetUrl', () => {
	const base = '/apps/exelearning/asset'

	it('builds a server-side asset URL from a file id and entry', () => {
		expect(buildAssetUrl(base, 42, 'html/page.html')).toBe(
			'/apps/exelearning/asset/42/html/page.html',
		)
	})

	it('encodes special characters per segment', () => {
		expect(buildAssetUrl(base, 7, 'content/Página 1.html')).toBe(
			'/apps/exelearning/asset/7/content/P%C3%A1gina%201.html',
		)
	})

	it('refuses unsafe entries', () => {
		expect(() => buildAssetUrl(base, 1, '../etc/passwd')).toThrow()
	})
})
