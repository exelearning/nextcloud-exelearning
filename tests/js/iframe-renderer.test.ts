import { describe, expect, it } from 'vitest'
import {
	buildSandboxedIframe,
	createContentIframe,
	createPackageIframe,
} from '../../src/elpx/iframe-renderer'
import { CONTENT_PREFIX, RUNTIME_PREFIX } from '../../src/elpx/paths'

describe('buildSandboxedIframe', () => {
	it('appends the eXeLearning teacher-mode param to the index src', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/content/tok/index.html', 'pkg')
		expect(iframe.src).toContain('exe-teacher=1')
		expect(iframe.src).toContain('/apps/exelearning/content/tok/index.html?exe-teacher=1')
	})

	it('uses & when the index src already carries a query string', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/content/tok/index.html?foo=bar', 'pkg')
		expect(iframe.src).toContain('?foo=bar&exe-teacher=1')
	})

	it('does not double-append when the param is already present', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/content/tok/index.html?exe-teacher=1', 'pkg')
		expect(iframe.src.match(/exe-teacher=1/g)).toHaveLength(1)
	})

	it('defaults to the secure opaque sandbox with NO allow-same-origin', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/content/tok/index.html', 'My package')
		const sandbox = iframe.getAttribute('sandbox') ?? ''
		expect(sandbox).toBe('allow-scripts allow-popups allow-forms')
		expect(sandbox).not.toContain('allow-same-origin')
		expect(sandbox).not.toContain('allow-popups-to-escape-sandbox')
		expect(iframe.title).toBe('My package')
	})

	it('uses the legacy same-origin sandbox when opaque=false', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/runtime/s/index.html', 'pkg', false)
		expect(iframe.getAttribute('sandbox')).toContain('allow-same-origin')
	})
})

describe('createContentIframe', () => {
	it('builds an opaque /content/{token} index src with the teacher-mode selector', () => {
		const iframe = createContentIframe({
			contentBase: CONTENT_PREFIX,
			token: 'cap-token-123',
			indexEntry: 'index.html',
			title: 'pkg',
		})
		expect(iframe.src).toContain(`${CONTENT_PREFIX}/cap-token-123/index.html?exe-teacher=1`)
		expect(iframe.getAttribute('sandbox')).not.toContain('allow-same-origin')
	})
})

describe('createPackageIframe (legacy Service Worker path)', () => {
	it('builds a same-origin runtime index src', () => {
		const iframe = createPackageIframe({
			runtimeBase: RUNTIME_PREFIX,
			sessionId: 'session-1',
			indexEntry: 'index.html',
			title: 'pkg',
		})
		expect(iframe.src).toContain(`${RUNTIME_PREFIX}/session-1/index.html?exe-teacher=1`)
		expect(iframe.getAttribute('sandbox')).toContain('allow-same-origin')
	})
})
