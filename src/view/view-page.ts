// Same publicPath fix as main.ts.
declare let __webpack_public_path__: string
declare global {
	interface Window {
		OC?: { appswebroots?: Record<string, string> }
	}
}
if (typeof window !== 'undefined' && window.OC?.appswebroots?.exelearning) {
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	__webpack_public_path__ = `${window.OC.appswebroots.exelearning}/js/`
}

import Vue from 'vue'
import { loadState } from '@nextcloud/initial-state'

import ElpxViewPage from './ElpxViewPage.vue'

interface InitialFile {
	id: number
	name: string
	path: string
	mtime: number
	etag: string
	writable: boolean
}

function safeLoad<T>(key: string, fallback: T): T {
	try {
		return (loadState('exelearning', key, fallback) as T) ?? fallback
	} catch {
		return fallback
	}
}

function boot(): void {
	const mount = document.getElementById('exelearning-view-root')
	if (!mount) return

	const file = safeLoad<InitialFile | null>('file', null)
	const editorAvailable = safeLoad<boolean>('editorAvailable', false)
	const editorIframeUrl = safeLoad<string>('editorIframeUrl', '/apps/exelearning/editor/iframe')
	const initialMode = safeLoad<'preview' | 'editor'>('initialMode', 'preview')

	new Vue({
		el: mount,
		render: (h) => h(ElpxViewPage, {
			props: { file, editorAvailable, editorIframeUrl, initialMode },
		}),
	})
}

boot()

export {}
