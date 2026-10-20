import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * The parent half of the shared bundle, asserted as the artifact this app actually ships.
 *
 * `relay-host.ts` drives `window.exeEmbedRelay`, a compatibility facade over the canonical
 * host. Nothing here would fail if that facade were dropped upstream — the calls would
 * simply become no-ops and every video would stop being promoted, silently. So the facade
 * is asserted against the vendored bytes rather than against a mock of them.
 */
const VENDORED = join(__dirname, '../../src/embed/exe_external_media')
const source = readFileSync(join(VENDORED, 'exe-external-media-host.min.js'), 'utf8')

/** Run the classic script the way a browser would, and return what it published. */
function evaluateBundle(): Record<string, unknown> {
	const globals = window as unknown as Record<string, unknown>
	for (const name of ['exeExternalMediaHost', 'exeEmbedRelay', 'exeMediaHost']) delete globals[name]
	new Function(source).call(window)
	return globals
}

describe('vendored external-media host bundle', () => {
	it('publishes the canonical host and the facade relay-host drives', () => {
		const globals = evaluateBundle()

		expect(typeof globals.exeExternalMediaHost).toBe('object')
		// The exact surface src/embed/relay-host.ts calls.
		expect(typeof (globals.exeEmbedRelay as { init?: unknown })?.init).toBe('function')
	})

	it('carries the media half the interactive-video iDevice needs', () => {
		const globals = evaluateBundle()

		expect(typeof (globals.exeMediaHost as { attach?: unknown })?.attach).toBe('function')
	})

	it('keeps the dual-licence notice through minification', () => {
		expect(source).toContain('Dual-licensed')
	})

	it('matches the digest core published for it', async () => {
		const manifest = JSON.parse(readFileSync(join(VENDORED, 'exe-external-media.manifest.json'), 'utf8'))
		const { createHash } = await import('node:crypto')

		expect(createHash('sha256').update(source).digest('hex')).toBe(manifest.files.host.sha256)
	})
})
