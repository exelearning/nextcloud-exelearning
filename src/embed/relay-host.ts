/**
 * Loads the eXe-core embed relay into the trusted parent (the Viewer page) and
 * drives it.
 *
 * It overlays real YouTube/Vimeo/Dailymotion/PDF players over the geometry
 * placeholders the shim reports from inside the opaque iframe.
 * `exe_embed_relay.js` attaches `window.exeEmbedRelay`.
 * The shim itself is NOT imported here — the PHP ContentController inlines it
 * into the served package so it runs inside the opaque iframe.
 */
import './exe_embed_relay.js'

interface RelayInstance {
	clear?: () => void
	reflow?: () => void
}

export interface EmbedRelayConfig {
	mode?: string
	whitelist?: string[]
}

interface EmbedRelayApi {
	init: (config: EmbedRelayConfig) => RelayInstance
}

interface BridgeGlobals {
	exeEmbedRelay?: EmbedRelayApi
}

/** The bridge globals attached to `window` by the imported mirror script. */
function globals(): BridgeGlobals {
	return window as unknown as BridgeGlobals
}

let relayInstance: RelayInstance | null = null

/**
 * Start the embed relay once (idempotent). Call after the first content iframe
 * mounts. 'strict' mode overlays only whitelisted providers; 'open' overlays any
 * cross-origin https player the shim reports.
 * @param config Relay mode + provider whitelist (defaults to open mode).
 */
export function startRelay(config: EmbedRelayConfig = { mode: 'open' }): void {
	if (relayInstance) {
		return
	}
	const api = globals().exeEmbedRelay
	if (api) {
		relayInstance = api.init(config)
	}
}

/**
 * Ask the shim inside the opaque iframe to (re)report its embed placeholders.
 * @param iframe The opaque content iframe to ping.
 */
export function pingEmbeds(iframe: HTMLIFrameElement): void {
	iframe.contentWindow?.postMessage({ type: 'exe-embed', action: 'request' }, '*')
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
