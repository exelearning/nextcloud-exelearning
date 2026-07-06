/**
 * Loads the eXe-core external-media bridge into the trusted parent (the Viewer
 * page) and drives it.
 *
 * - Channel A (`exe-embed`): overlays real YouTube/Vimeo/Dailymotion/PDF players
 *   over the geometry placeholders the shim reports from inside the opaque
 *   iframe. `exe_embed_relay.js` attaches `window.exeEmbedRelay`.
 * - Channel B (`exe-media`): hosts the interactive-video iDevice modal bridge.
 *   `exe_media_host.js` (+ its `exe_media_policy.js` dependency) attaches
 *   `window.exeMediaHost`.
 *
 * The shim itself is NOT imported here — the PHP ContentController inlines it
 * into the served package so it runs inside the opaque iframe.
 */
import './exe_embed_relay.js'
import './exe_media_policy.js'
import './exe_media_host.js'

interface RelayInstance {
	clear?: () => void
	reflow?: () => void
}

interface EmbedRelayApi {
	init: (config: { mode?: string, whitelist?: string[] }) => RelayInstance
}

interface MediaHostApi {
	attach: (iframe: HTMLIFrameElement, opts: Record<string, unknown>) => void
}

interface BridgeGlobals {
	exeEmbedRelay?: EmbedRelayApi
	exeMediaHost?: MediaHostApi
}

/** The bridge globals attached to `window` by the imported mirror scripts. */
function globals(): BridgeGlobals {
	return window as unknown as BridgeGlobals
}

let relayInstance: RelayInstance | null = null

/**
 * Start the embed relay once (idempotent). Call after the first content iframe
 * mounts. `open` mode overlays any cross-origin https player the shim reports.
 */
export function startRelay(): void {
	if (relayInstance) {
		return
	}
	const api = globals().exeEmbedRelay
	if (api) {
		relayInstance = api.init({ mode: 'open' })
	}
}

/**
 * Ask the shim inside the opaque iframe to (re)report its embed placeholders.
 * @param iframe The opaque content iframe to ping.
 */
export function pingEmbeds(iframe: HTMLIFrameElement): void {
	iframe.contentWindow?.postMessage({ type: 'exe-embed', action: 'request' }, '*')
}

/**
 * Attach the interactive-video media host to a content iframe (Channel B).
 * @param iframe The opaque content iframe to attach the media host to.
 */
export function attachMedia(iframe: HTMLIFrameElement): void {
	globals().exeMediaHost?.attach(iframe, {})
}

/** Remove all overlay players — call when the iframe is hidden or torn down. */
export function clearOverlays(): void {
	relayInstance?.clear?.()
}

/** Reposition overlays against the iframe's current box — call after a resize/move. */
export function reflowOverlays(): void {
	relayInstance?.reflow?.()
}

/** Test-only: reset the memoised relay instance between cases. */
export function resetRelayForTests(): void {
	relayInstance = null
}
