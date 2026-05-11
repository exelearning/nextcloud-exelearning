import { describe, expect, it } from 'vitest'
import { hasElpxExtension, isElpxFile, PRIMARY_MIME } from '../../src/files/mime'

describe('hasElpxExtension', () => {
	it('matches .elpx and .elp regardless of case', () => {
		expect(hasElpxExtension('project.elpx')).toBe(true)
		expect(hasElpxExtension('PROJECT.ELPX')).toBe(true)
		expect(hasElpxExtension('legacy.elp')).toBe(true)
		expect(hasElpxExtension('something.zip')).toBe(false)
	})
})

describe('isElpxFile', () => {
	it('accepts the vendor MIME without checking the name', () => {
		expect(isElpxFile({ mime: PRIMARY_MIME })).toBe(true)
	})

	it('falls back to the extension for generic zip/octet-stream files', () => {
		expect(isElpxFile({ mime: 'application/zip', basename: 'course.elpx' })).toBe(true)
		expect(isElpxFile({ mime: 'application/octet-stream', name: 'course.elpx' })).toBe(true)
		expect(isElpxFile({ mime: 'application/zip', name: 'archive.zip' })).toBe(false)
	})
})
