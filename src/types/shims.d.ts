declare module '*.vue' {
	import type { DefineComponent } from 'vue'
	const component: DefineComponent<object, object, unknown>
	export default component
}

declare module '@nextcloud/viewer' {
	export function registerHandler(handler: {
		id: string
		group?: string
		mimes: string[]
		component: unknown
		theme?: string
	}): void
	const _default: { registerHandler: typeof registerHandler; open?: (options: { path: string }) => void }
	export default _default
}

declare interface Window {
	OCA?: {
		Viewer?: {
			registerHandler?: (handler: unknown) => void
			open?: (options: { path: string }) => void
		}
	}
}
