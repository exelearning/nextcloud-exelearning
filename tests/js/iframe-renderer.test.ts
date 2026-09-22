import { describe, expect, it } from 'vitest'
import {
	buildSandboxedIframe,
	createPackageIframe,
} from '../../src/elpx/iframe-renderer'
import { RUNTIME_PREFIX } from '../../src/elpx/paths'

describe('buildSandboxedIframe', () => {
	it('appends the eXeLearning teacher-mode param to the index src', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/asset/42/index.html', 'pkg')
		expect(iframe.src).toContain('exe-teacher=1')
		expect(iframe.src).toContain('/apps/exelearning/asset/42/index.html?exe-teacher=1')
	})

	it('uses & when the index src already carries a query string', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/asset/42/index.html?foo=bar', 'pkg')
		expect(iframe.src).toContain('?foo=bar&exe-teacher=1')
	})

	it('does not double-append when the param is already present', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/asset/42/index.html?exe-teacher=1', 'pkg')
		expect(iframe.src.match(/exe-teacher=1/g)).toHaveLength(1)
	})

	it('sets the sandbox flags and accessible title', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/asset/42/index.html', 'My package')
		expect(iframe.getAttribute('sandbox')).toContain('allow-scripts')
		expect(iframe.title).toBe('My package')
	})

	it('rewires external links after the iframe loads', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/asset/42/index.html', 'pkg')
		Object.defineProperty(iframe, 'contentDocument', {
			configurable: true,
			value: document,
		})
		const host = document.createElement('div')
		document.body.appendChild(host)

		const external = document.createElement('a')
		external.setAttribute('href', 'https://example.com/resource')
		const child = document.createElement('span')
		child.textContent = 'External'
		external.appendChild(child)
		host.appendChild(external)

		const internal = document.createElement('a')
		internal.setAttribute('href', 'page.html')
		internal.textContent = 'Internal'
		host.appendChild(internal)

		const noHref = document.createElement('a')
		noHref.textContent = 'No href'
		host.appendChild(noHref)

		iframe.dispatchEvent(new Event('load'))
		child.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		internal.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		noHref.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		host.dispatchEvent(new MouseEvent('click', { bubbles: true }))

		expect(external.getAttribute('target')).toBe('_blank')
		expect(external.getAttribute('rel')).toBe('noopener noreferrer')
		expect(internal.getAttribute('target')).toBeNull()
		host.remove()
	})

	it('swallows content-document access errors on load', () => {
		const iframe = buildSandboxedIframe('/apps/exelearning/asset/42/index.html', 'pkg')
		Object.defineProperty(iframe, 'contentDocument', {
			configurable: true,
			get() {
				throw new Error('cross-origin')
			},
		})

		expect(() => iframe.dispatchEvent(new Event('load'))).not.toThrow()
	})
})

describe('createPackageIframe', () => {
	it('builds a runtime index src that exposes the teacher-mode selector', () => {
		const iframe = createPackageIframe({
			runtimeBase: RUNTIME_PREFIX,
			sessionId: 'session-1',
			indexEntry: 'index.html',
			title: 'pkg',
		})
		expect(iframe.src).toContain(`${RUNTIME_PREFIX}/session-1/index.html?exe-teacher=1`)
	})
})
