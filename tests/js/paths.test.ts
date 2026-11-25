import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createContext, runInContext } from 'node:vm'
import { describe, expect, it } from 'vitest'
import {
	buildAssetUrl,
	buildContentUrl,
	buildRuntimeUrl,
	isExternalUrl,
	normalizeEntryPath,
	parseRuntimeUrl,
	resolveRelativeEntry,
	RUNTIME_PREFIX,
} from '../../src/elpx/paths'
import vectorTable from '../fixtures/entry-path-vectors.json'

const VECTORS = vectorTable.vectors

/**
 * Loads the Service Worker's inline `normalizeEntry` mirror.
 *
 * The worker is deliberately not importable — it ships as a classic script
 * loaded out-of-band by the browser, so it cannot pull in bundled application
 * code (see `src/sw/exelearning-sw.js`). Evaluating the real file in a VM
 * context with a stub `self` is therefore the only way to test the shipped
 * copy rather than a transcription of it. Top-level function declarations
 * become properties of the VM's global object, which is how the mirror is
 * reached.
 *
 * The path is resolved from the Vitest root (`process.cwd()`) because under
 * the happy-dom environment `import.meta.url` is not a `file:` URL.
 */
function loadServiceWorkerNormalizer(): (input: unknown) => string | null {
	const workerPath = resolve(process.cwd(), 'src/sw/exelearning-sw.js')
	const source = readFileSync(workerPath, 'utf8')
	const context = createContext({
		self: {
			addEventListener: () => {},
			skipWaiting: () => {},
			clients: { claim: () => {} },
		},
	}) as Record<string, unknown>
	runInContext(source, context, { filename: workerPath })
	const mirror = context.normalizeEntry
	if (typeof mirror !== 'function') {
		throw new Error('normalizeEntry was not found in the Service Worker source')
	}
	return mirror as (input: unknown) => string | null
}

const normalizeEntryInServiceWorker = loadServiceWorkerNormalizer()

/**
 * The shared vector table is the contract between the three implementations
 * of the entry-path rule: this TypeScript helper, the Service Worker mirror
 * above, and `ZipEntryService::normalizeEntry` in PHP (which runs the same
 * table from `tests/Unit/Service/ZipEntryServiceTest.php`). They diverged
 * once — a package with an `a/b/../c` entry rendered in the browser but 404'd
 * from the PHP asset route — so the table exists to make a divergence fail a
 * test instead of shipping.
 */
describe('entry-path rule (shared vectors)', () => {
	it('covers every case the rule has to answer', () => {
		expect(VECTORS.length).toBeGreaterThanOrEqual(20)
		expect(VECTORS.some((vector) => vector.expected !== null)).toBe(true)
	})

	for (const { input, expected, why } of VECTORS) {
		const label = `${JSON.stringify(input)} → ${JSON.stringify(expected)} (${why})`

		it(`normalizeEntryPath: ${label}`, () => {
			expect(normalizeEntryPath(input)).toBe(expected)
		})

		it(`service worker mirror: ${label}`, () => {
			expect(normalizeEntryInServiceWorker(input)).toBe(expected)
		})
	}

	it('never rewrites: an accepted path comes back byte-identical', () => {
		for (const { input, expected } of VECTORS) {
			if (expected !== null) {
				expect(expected).toBe(input)
				expect(normalizeEntryPath(input)).toBe(input)
				expect(normalizeEntryInServiceWorker(input)).toBe(input)
			}
		}
	})
})

describe('normalizeEntryPath', () => {
	it('rejects near-miss paths instead of repairing them', () => {
		// The pre-convergence helper answered 'html/page.html' to all three.
		// Repairing them addresses an entry other than the one stored in the
		// archive, so they are refused now.
		expect(normalizeEntryPath('/html/page.html')).toBeNull()
		expect(normalizeEntryPath('html/./page.html')).toBeNull()
		expect(normalizeEntryPath('html\\page.html')).toBeNull()
	})
})

describe('resolveRelativeEntry', () => {
	it('resolves siblings of the base entry', () => {
		expect(resolveRelativeEntry('html/page.html', 'image.png')).toBe('html/image.png')
	})

	it('resolves up-references within the package', () => {
		expect(resolveRelativeEntry('html/sub/page.html', '../image.png')).toBe('html/image.png')
	})

	it('resolves current-directory references', () => {
		expect(resolveRelativeEntry('html/page.html', './image.png')).toBe('html/image.png')
	})

	it('resolves root-relative hrefs against the package root', () => {
		expect(resolveRelativeEntry('html/sub/page.html', '/theme/style.css')).toBe('theme/style.css')
	})

	it('rejects escapes from the package root', () => {
		expect(resolveRelativeEntry('html/page.html', '../../etc/passwd')).toBeNull()
		expect(resolveRelativeEntry('page.html', '../etc/passwd')).toBeNull()
	})

	it('rejects hrefs that resolve onto a non-canonical entry', () => {
		expect(resolveRelativeEntry('html/page.html', 'sub//image.png')).toBeNull()
		expect(resolveRelativeEntry('html/page.html', 'sub/')).toBeNull()
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

describe('buildContentUrl', () => {
	const base = '/apps/exelearning/content'

	it('builds an opaque content URL from a capability token and entry', () => {
		expect(buildContentUrl(base, 'cap-token', 'html/page.html')).toBe(
			'/apps/exelearning/content/cap-token/html/page.html',
		)
	})

	it('encodes the token and each entry segment', () => {
		expect(buildContentUrl(base, 'a.b_c-d', 'content/Página 1.html')).toBe(
			'/apps/exelearning/content/a.b_c-d/content/P%C3%A1gina%201.html',
		)
	})

	it('refuses unsafe entries', () => {
		expect(() => buildContentUrl(base, 'tok', '../etc/passwd')).toThrow()
	})
})
