import { beforeAll, beforeEach, describe, expect, it } from 'vitest'
// Side-effect import: the mirror attaches its API on window.exeEmbedRelay,
// exactly as relay-host.ts loads it in the browser. This exercises the raw
// eXe-core relay mirror (src/embed/exe_embed_relay.js) directly, not the
// relay-host.ts wrapper (which mocks the bridge).
import '../../src/embed/exe_embed_relay.js'

interface RelayInstance {
	onMessage: (event: { source: unknown; data: unknown }) => void
	checkDrift: () => number
	reflow: () => void
}

interface EmbedRelayApi {
	createRelay: (config: { mode: string }) => RelayInstance
}

const relay = (window as unknown as { exeEmbedRelay: EmbedRelayApi }).exeEmbedRelay

/** A full DOMRect from a box, so it can be assigned to getBoundingClientRect under strict TS. */
function fakeRect(left: number, top: number, width: number, height: number): DOMRect {
	return {
		left,
		top,
		width,
		height,
		right: left + width,
		bottom: top + height,
		x: left,
		y: top,
		toJSON: () => ({}),
	}
}

describe('exe_embed_relay checkDrift (mirror of eXe core)', () => {
	let iframe: HTMLIFrameElement

	beforeAll(() => {
		// The relay overlays a real <iframe> player pointing at the (cross-origin)
		// provider URL. Keep the unit test deterministic and offline: stop
		// happy-dom from navigating child frames (it falls back to just setting the
		// URL, no network call) and from loading CSS/JS files, so no escaped
		// fire-and-forget rejection noises the run.
		interface HappyDomSettings {
			disableJavaScriptFileLoading: boolean
			disableCSSFileLoading: boolean
			handleDisabledFileLoadingAsSuccess: boolean
			navigation: { disableChildFrameNavigation: boolean }
		}
		const dom = (window as unknown as { happyDOM?: { settings?: HappyDomSettings } }).happyDOM
		if (dom?.settings) {
			dom.settings.navigation.disableChildFrameNavigation = true
			dom.settings.disableJavaScriptFileLoading = true
			dom.settings.disableCSSFileLoading = true
			dom.settings.handleDisabledFileLoadingAsSuccess = true
		}
		// This happy-dom build leaves window.location.hostname undefined, which the
		// relay's cross-origin check relies on (real browsers always set it).
		if (!window.location.hostname) {
			try {
				Object.defineProperty(window.location, 'hostname', {
					value: new URL(window.location.href).hostname,
					configurable: true,
				})
			} catch {
				/* location may be read-only in some environments */
			}
		}
	})

	beforeEach(() => {
		document.body.innerHTML = ''
		iframe = document.createElement('iframe')
		document.body.appendChild(iframe)
	})

	it('re-pins an overlay whose content iframe moved without any event', () => {
		const r = relay.createRelay({ mode: 'open' })
		r.onMessage({
			source: iframe.contentWindow,
			data: {
				type: 'exe-embed',
				action: 'sync',
				embeds: [{ id: 'e1', url: 'https://www.youtube.com/embed/abc123', x: 0, y: 0, w: 480, h: 270 }],
			},
		})
		const overlay = document.querySelector<HTMLElement>('.exe-embed-overlay')
		expect(overlay).not.toBeNull()

		// Nothing moved yet: the drift check must be a no-op.
		expect(r.checkDrift()).toBe(0)

		// The host toggles a sidebar: the iframe box shifts with no scroll/resize.
		iframe.getBoundingClientRect = () => fakeRect(120, 30, 500, 320)
		expect(r.checkDrift()).toBe(1)
		expect(overlay?.style.left).toBe('120px')
		expect(overlay?.style.top).toBe('30px')
		expect(overlay?.style.width).toBe('500px')

		// Settled: a second pass changes nothing.
		expect(r.checkDrift()).toBe(0)
	})
})
