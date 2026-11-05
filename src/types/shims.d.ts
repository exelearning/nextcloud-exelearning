declare module '*.vue' {
	import type { DefineComponent } from 'vue'
	const component: DefineComponent<object, object, unknown>
	export default component
}

// The eXe-core embed/media bridge mirrors are plain-JS, side-effect modules
// (they attach window.exeEmbedRelay). We import them only
// for their side effects and use the window globals via typed casts.
declare module '*/exe-external-media-host.min.js'
declare module '*/exe-external-media-child.min.js'
