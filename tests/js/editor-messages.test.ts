import { describe, expect, it } from 'vitest'
import { isEditorMessage, normalizeBytes } from '../../src/editor/editor-messages'

describe('isEditorMessage', () => {
	it('accepts objects with a string type', () => {
		expect(isEditorMessage({ type: 'SAVE_FILE' })).toBe(true)
		expect(isEditorMessage({ type: 'CUSTOM_EVENT', data: { ok: true } })).toBe(true)
	})

	it('rejects invalid message shapes', () => {
		expect(isEditorMessage(null)).toBe(false)
		expect(isEditorMessage('SAVE_FILE')).toBe(false)
		expect(isEditorMessage({})).toBe(false)
		expect(isEditorMessage({ type: 123 })).toBe(false)
	})
})

describe('normalizeBytes', () => {
	it('returns an ArrayBuffer payload directly', () => {
		const buffer = new Uint8Array([1, 2, 3]).buffer
		expect(normalizeBytes(buffer)).toBe(buffer)
	})

	it('copies only the visible bytes from an ArrayBuffer view', () => {
		const source = new Uint8Array([9, 1, 2, 3, 8])
		const view = new Uint8Array(source.buffer, 1, 3)
		const normalized = normalizeBytes(view)

		expect(normalized).not.toBe(source.buffer)
		expect(Array.from(new Uint8Array(normalized))).toEqual([1, 2, 3])
	})

	it('throws when the editor does not return binary data', () => {
		expect(() => normalizeBytes({ bytes: [1, 2, 3] })).toThrow(
			'The editor returned a save payload without binary bytes.',
		)
	})
})
