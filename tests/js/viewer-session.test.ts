import { afterEach, describe, expect, it, vi } from 'vitest'
import { ViewerSession } from '../../src/elpx/viewer-session'

describe('ViewerSession', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('creates an isolated session with MIME metadata for every entry', () => {
		vi.spyOn(globalThis.crypto, 'randomUUID')
			.mockReturnValue('00000000-0000-4000-8000-000000000001')
		vi.spyOn(Date, 'now').mockReturnValue(1_700_000_000_000)

		const html = new Uint8Array([60, 104, 49, 62])
		const image = new Uint8Array([1, 2, 3])
		const session = ViewerSession.create({
			entries: new Map([
				['index.html', html],
				['images/logo.png', image],
			]),
			indexEntry: 'index.html',
			filename: 'lesson.elpx',
		})

		expect(session.id).toBe('00000000-0000-4000-8000-000000000001')
		expect(session.indexEntry).toBe('index.html')
		expect(session.filename).toBe('lesson.elpx')
		expect(session.data.createdAt).toBe(1_700_000_000_000)
		expect(session.file('index.html')).toEqual({
			bytes: html,
			mime: 'text/html; charset=utf-8',
		})
		expect(session.file('images/logo.png')).toEqual({
			bytes: image,
			mime: 'image/png',
		})
		expect(session.file('missing.txt')).toBeUndefined()
	})

	it('uses getRandomValues when randomUUID is unavailable', () => {
		vi.stubGlobal('crypto', {
			getRandomValues(buffer: Uint8Array) {
				buffer.fill(0xab)
				return buffer
			},
		})

		const session = ViewerSession.create({
			entries: new Map(),
			indexEntry: 'index.html',
			filename: 'fallback.elpx',
		})

		expect(session.id).toBe('ab'.repeat(16))
	})

	it('falls back to Math.random when Web Crypto is unavailable', () => {
		vi.stubGlobal('crypto', undefined)
		vi.spyOn(Math, 'random').mockReturnValue(0.5)

		const session = ViewerSession.create({
			entries: new Map(),
			indexEntry: 'index.html',
			filename: 'legacy-browser.elpx',
		})

		expect(session.id).toBe('80'.repeat(16))
	})
})
