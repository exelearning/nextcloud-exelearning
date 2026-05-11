import { describe, expect, it } from 'vitest'
import { isHtmlEntry, mimeForEntry } from '../../src/elpx/asset-map'

describe('mimeForEntry', () => {
	it.each([
		['index.html', 'text/html; charset=utf-8'],
		['page.HTM', 'text/html; charset=utf-8'],
		['theme/style.css', 'text/css; charset=utf-8'],
		['libs/jquery.js', 'text/javascript; charset=utf-8'],
		['libs/mod.mjs', 'text/javascript; charset=utf-8'],
		['data/items.json', 'application/json; charset=utf-8'],
		['media/audio.mp3', 'audio/mpeg'],
		['media/video.mp4', 'video/mp4'],
		['media/captions.vtt', 'text/vtt'],
		['fonts/regular.woff2', 'font/woff2'],
		['images/hero.svg', 'image/svg+xml'],
		['images/hero.png', 'image/png'],
	])('maps %s to %s', (entry, expected) => {
		expect(mimeForEntry(entry)).toBe(expected)
	})

	it('falls back to octet-stream for unknown extensions', () => {
		expect(mimeForEntry('content/blob.bin')).toBe('application/octet-stream')
		expect(mimeForEntry('no-extension')).toBe('application/octet-stream')
	})
})

describe('isHtmlEntry', () => {
	it('is true for html / htm / xhtml only', () => {
		expect(isHtmlEntry('index.html')).toBe(true)
		expect(isHtmlEntry('page.htm')).toBe(true)
		expect(isHtmlEntry('page.xhtml')).toBe(true)
		expect(isHtmlEntry('style.css')).toBe(false)
		expect(isHtmlEntry('image.png')).toBe(false)
	})
})
