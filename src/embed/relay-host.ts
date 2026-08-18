/**
 * Loads the eXe-core embed relay into the trusted parent (the Viewer page) and
 * drives it.
 *
 * It overlays real YouTube/Vimeo/Dailymotion/PDF players over the geometry
 * placeholders the shim reports from inside the opaque iframe.
 * The vendored host bundle attaches `window.exeExternalMediaHost` plus the
 * `window.exeEmbedRelay` facade this module drives.
 * The shim itself is NOT imported here — the PHP ContentController inlines it
 * into the served package so it runs inside the opaque iframe.
 */
import './exe_external_media/exe-external-media-host.min.js'

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

interface AttachedMediaHost {
	detach: () => void
}

interface ExternalMediaHostApi {
	attachMedia: (iframe: HTMLIFrameElement) => AttachedMediaHost
}

interface BridgeGlobals {
	exeEmbedRelay?: EmbedRelayApi
	exeExternalMediaHost?: ExternalMediaHostApi
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

/** One attachment per content iframe; the media host owns the pairing, not the page. */
const mediaHosts = new WeakMap<HTMLIFrameElement, AttachedMediaHost>()

/**
 * Attach the MEDIA half to a content iframe.
 *
 * Separate from `startRelay` because the two halves are adopted separately (exelearning/exelearning ADR-2199-15):
 * the relay promotes declarative embeds it finds by scanning, while this answers an iDevice
 * that asks the host to open and drive a video over a private port. A viewer with only the
 * relay looks completely healthy — embeds are promoted, nothing errors — while every bridged
 * video in the package is dead, because nobody replies to the handshake.
 *
 * Memoised per iframe rather than per page: the viewer can mount more than one, and each
 * needs its own session.
 * @param iframe The opaque content iframe to serve videos for.
 */
export function startMedia(iframe: HTMLIFrameElement): void {
	if (mediaHosts.has(iframe)) {
		return
	}
	const api = globals().exeExternalMediaHost
	if (api) {
		mediaHosts.set(iframe, api.attachMedia(iframe))
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
