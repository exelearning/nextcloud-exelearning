import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	attachMedia,
	clearOverlays,
	pingEmbeds,
	reflowOverlays,
	resetRelayForTests,
	startRelay,
} from '../../src/embed/relay-host'

type WindowWithBridge = Window & {
	exeEmbedRelay?: unknown
	exeMediaHost?: unknown
}

const w = window as WindowWithBridge

afterEach(() => {
	resetRelayForTests()
	delete w.exeEmbedRelay
	delete w.exeMediaHost
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

	it('attachMedia attaches the media host to the iframe', () => {
		const attach = vi.fn()
		w.exeMediaHost = { attach }
		const iframe = {} as HTMLIFrameElement
		attachMedia(iframe)
		expect(attach).toHaveBeenCalledWith(iframe, {})
	})

	it('clear/reflow/attach are safe no-ops when the bridge is absent', () => {
		expect(() => {
			clearOverlays()
			reflowOverlays()
			attachMedia({} as HTMLIFrameElement)
		}).not.toThrow()
	})
})
