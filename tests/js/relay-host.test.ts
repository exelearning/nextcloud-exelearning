import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	clearOverlays,
	pingEmbeds,
	reflowOverlays,
	resetRelayForTests,
	startMedia,
	startRelay,
} from '../../src/embed/relay-host'

type WindowWithBridge = Window & {
	exeEmbedRelay?: unknown
	exeExternalMediaHost?: unknown
}

const w = window as WindowWithBridge

afterEach(() => {
	resetRelayForTests()
	delete w.exeEmbedRelay
	delete w.exeExternalMediaHost
})

describe('relay-host', () => {
	it('startRelay inits the embed relay once, in open mode', () => {
		const instance = { clear: vi.fn(), reflow: vi.fn() }
		const init = vi.fn().mockReturnValue(instance)
		w.exeEmbedRelay = { init }
		startRelay()
		startRelay()
		expect(init).toHaveBeenCalledTimes(1)
		expect(init).toHaveBeenCalledWith({ mode: 'open' })
	})

	it('passes a custom strict mode + whitelist through to init', () => {
		const init = vi.fn().mockReturnValue({})
		w.exeEmbedRelay = { init }
		startRelay({ mode: 'strict', whitelist: ['player.vimeo.com'] })
		expect(init).toHaveBeenCalledWith({ mode: 'strict', whitelist: ['player.vimeo.com'] })
	})

	it('clearOverlays / reflowOverlays drive the relay instance', () => {
		const instance = { clear: vi.fn(), reflow: vi.fn() }
		w.exeEmbedRelay = { init: () => instance }
		startRelay()
		clearOverlays()
		reflowOverlays()
		expect(instance.clear).toHaveBeenCalledOnce()
		expect(instance.reflow).toHaveBeenCalledOnce()
	})

	it('pingEmbeds posts the exe-embed request to the iframe window', () => {
		const postMessage = vi.fn()
		const iframe = { contentWindow: { postMessage } } as unknown as HTMLIFrameElement
		pingEmbeds(iframe)
		expect(postMessage).toHaveBeenCalledWith({ type: 'exe-embed', action: 'request' }, '*')
	})

	/**
	 * The media half is adopted SEPARATELY from the embed half (exelearning/exelearning ADR-2199-15): starting the
	 * relay overlays declarative embeds, and does nothing for an iDevice that asks the host
	 * to drive a video. Without this the interactive-video iDevice inside the opaque package
	 * gets no answer to its handshake and falls back to loading YouTube's SDK, which the
	 * content CSP blocks — measured here, with the evidence harness, as a silent failure
	 * every existing assertion sailed straight through.
	 */
	it('startMedia attaches the media host to the content iframe', () => {
		const attachMedia = vi.fn().mockReturnValue({ detach: vi.fn() })
		w.exeExternalMediaHost = { attachMedia }
		const iframe = { contentWindow: {} } as unknown as HTMLIFrameElement

		startMedia(iframe)

		expect(attachMedia).toHaveBeenCalledWith(iframe)
	})

	it('startMedia attaches once per iframe, not once per page', () => {
		const attachMedia = vi.fn().mockReturnValue({ detach: vi.fn() })
		w.exeExternalMediaHost = { attachMedia }
		const first = { contentWindow: {} } as unknown as HTMLIFrameElement
		const second = { contentWindow: {} } as unknown as HTMLIFrameElement

		startMedia(first)
		startMedia(first)
		startMedia(second)

		expect(attachMedia).toHaveBeenCalledTimes(2)
	})

	it('startMedia is a safe no-op when the host bundle is absent', () => {
		const iframe = { contentWindow: {} } as unknown as HTMLIFrameElement
		expect(() => startMedia(iframe)).not.toThrow()
	})

	it('clear/reflow are safe no-ops when the relay is absent', () => {
		expect(() => {
			clearOverlays()
			reflowOverlays()
		}).not.toThrow()
	})
})
