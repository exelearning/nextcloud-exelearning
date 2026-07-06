declare module '*.vue' {
	import type { DefineComponent } from 'vue'
	const component: DefineComponent<object, object, unknown>
	export default component
}

// The eXe-core embed/media bridge mirrors are plain-JS, side-effect modules
// (they attach window.exeEmbedRelay / window.exeMediaHost). We import them only
// for their side effects and use the window globals via typed casts.
declare module '*/exe_embed_relay.js'
declare module '*/exe_embed_shim.js'
declare module '*/exe_media_policy.js'
declare module '*/exe_media_host.js'
