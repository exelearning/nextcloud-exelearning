<template>
	<div class="exelearning-viewer">
		<div ref="frameSlot" class="exelearning-viewer__frame-slot">
			<div v-if="state === 'loading'" class="exelearning-viewer__loading">
				<span class="icon-loading" aria-hidden="true" />
				<p>{{ status || t('exelearning', 'Opening eXeLearning package…') }}</p>
			</div>
			<div v-else-if="state === 'legacy'" class="exelearning-viewer__legacy">
				<h2>{{ t('exelearning', 'Older eXeLearning package') }}</h2>
				<p>
					{{ t('exelearning', 'This file was created with an older version of eXeLearning and cannot be previewed directly.') }}
				</p>
				<p>
					{{ t('exelearning', 'Open it in the editor to migrate it — when you save it will be stored as a modern .elpx package.') }}
				</p>
				<button v-if="canMigrate"
					type="button"
					class="exelearning-viewer__legacy-action"
					@click="$emit('migrate-in-editor')">
					{{ t('exelearning', 'Open in eXeLearning editor') }}
				</button>
				<p v-else class="exelearning-viewer__legacy-readonly">
					{{ t('exelearning', 'You do not have permission to migrate this file. Ask the owner to open and re-save it in eXeLearning.') }}
				</p>
			</div>
			<ViewerError v-else-if="state === 'error'"
				:title="errorTitle"
				:message="errorMessage"
				:detail="errorDetail" />
		</div>
	</div>
</template>

<script lang="ts">
import { defineComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

import ViewerError from './ViewerError.vue'

import { loadElpx } from '../elpx/elpx-loader'
import { readPackage, ZipReadError } from '../elpx/zip-reader'
import { validatePackage } from '../elpx/package-validator'
import { ViewerSession } from '../elpx/viewer-session'
import { ensureRuntimeWorker, registerSession, unregisterSession, type RuntimeWorker } from '../elpx/service-worker-client'
import { createContentIframe, createPackageIframe, buildSandboxedIframe } from '../elpx/iframe-renderer'
import { ASSET_PREFIX, buildAssetUrl, CONTENT_PREFIX } from '../elpx/paths'
import { attachMedia, clearOverlays, pingEmbeds, reflowOverlays, startRelay } from '../embed/relay-host'

/**
 * Reads a server-provided initial-state value, falling back on any error so the
 * viewer still boots (e.g. in the @nextcloud/viewer modal, where the /view
 * ViewController never ran and these keys are absent → legacy path).
 * @param key The initial-state key registered server-side.
 * @param fallback Value to return when the key is missing or invalid.
 */
function safeLoad<T>(key: string, fallback: T): T {
	try {
		return (loadState('exelearning', key, fallback) as T) ?? fallback
	} catch {
		return fallback
	}
}

type State = 'loading' | 'ready' | 'legacy' | 'error'

interface Data {
	state: State
	status: string
	errorTitle: string
	errorMessage: string
	errorDetail: string
	session: ViewerSession | null
	worker: RuntimeWorker | null
	iframe: HTMLIFrameElement | null
	relayActive: boolean
}

export default defineComponent({
	name: 'ElpxViewer',
	components: { ViewerError },
	props: {
		// @nextcloud/viewer passes these for any registered handler.
		filename: { type: String, default: '' },
		basename: { type: String, default: '' },
		source: { type: String, default: '' },
		mime: { type: String, default: '' },
		// `fileid` is the canonical Nextcloud file id.
		fileid: { type: [Number, String], default: undefined },
		// Whether the current user can write to the file. Drives the
		// "Open in editor" button on the legacy notice — if the file is
		// read-only, migrating it would fail at save time anyway.
		canMigrate: { type: Boolean, default: true },
	},
	emits: ['migrate-in-editor'],
	data(): Data {
		return {
			state: 'loading',
			status: '',
			errorTitle: '',
			errorMessage: '',
			errorDetail: '',
			session: null,
			worker: null,
			iframe: null,
			relayActive: false,
		}
	},
	mounted() {
		void this.open()
	},
	beforeUnmount() {
		this.teardown()
	},
	methods: {
		t,
		async open(): Promise<void> {
			this.state = 'loading'
			this.status = t('exelearning', 'Downloading package…')
			try {
				const fileId = this.fileid !== undefined ? Number(this.fileid) : undefined
				const loaded = await loadElpx({
					...(fileId !== undefined && Number.isFinite(fileId) ? { fileId } : {}),
					...(fileId === undefined && this.filename ? { path: this.filename } : {}),
				})

				this.status = t('exelearning', 'Extracting package…')
				const { entries } = await readPackage(loaded.bytes)
				const sourceFilename = loaded.filename || this.filename || this.basename || ''
				const validation = validatePackage(entries, sourceFilename)
				if (validation.legacy) {
					// Pre-`.elpx` project. Bail out of the viewer path and let
					// the parent show the migration prompt — the editor is
					// the only thing that knows how to upgrade these.
					this.state = 'legacy'
					this.status = ''
					return
				}
				if (!validation.valid || validation.shape.indexEntry === null) {
					throw new Error(validation.error ?? 'The package is missing index.html.')
				}

				const indexEntry = validation.shape.indexEntry
				const title = loaded.filename || this.filename || this.basename || 'package.elpx'

				// Secure (default): serve the package into an opaque-origin iframe
				// over the cookieless /content/{token} route. No Service Worker and
				// no in-memory session — the server streams entries from the stored
				// file, and external media is relayed to this trusted page.
				const contentToken = safeLoad<string | null>('contentToken', null)
				const secureIframe = safeLoad<boolean>('secureIframe', false)
				if (secureIframe && contentToken && fileId !== undefined && Number.isFinite(fileId)) {
					this.state = 'ready'
					this.status = ''
					this.$nextTick(() => this.attachContentIframe(contentToken, indexEntry, title))
					return
				}

				// Legacy escape hatch (EXELEARNING_UNSAFE_LEGACY_IFRAME): same-origin
				// Service-Worker path with a server-side asset fallback.
				this.status = t('exelearning', 'Preparing viewer…')
				const session = ViewerSession.create({
					entries,
					indexEntry,
					filename: title,
				})

				try {
					const worker = await ensureRuntimeWorker()
					await registerSession(worker, session)

					this.session = session
					this.worker = worker
					this.state = 'ready'
					this.status = ''
					this.$nextTick(() => this.attachIframe(worker, session))
				} catch (swError) {
					// The runtime Service Worker can't be registered — e.g. when
					// Nextcloud is embedded in another origin that already owns a
					// controlling Service Worker (the browser fetches the SW
					// script straight from the network, bypassing it, so the
					// virtual /apps/exelearning/sw.js route 404s). Fall back to the
					// server-side AssetController, which streams each entry from the
					// stored package. Needs the canonical file id.
					if (fileId === undefined || !Number.isFinite(fileId)) {
						throw swError
					}
					console.warn('[exelearning] Service Worker unavailable; using server-side asset fallback.', swError)
					this.state = 'ready'
					this.status = ''
					this.$nextTick(() => this.attachServerIframe(fileId, indexEntry))
				}
			} catch (error) {
				this.handleError(error)
			}
		},
		attachIframe(worker: RuntimeWorker, session: ViewerSession): void {
			const slot = this.$refs.frameSlot as HTMLElement | undefined
			if (!slot) return
			slot.innerHTML = ''
			const iframe = createPackageIframe({
				runtimeBase: worker.runtimeBase,
				sessionId: session.id,
				indexEntry: session.indexEntry,
				title: session.filename,
			})
			slot.appendChild(iframe)
			this.iframe = iframe
		},
		attachServerIframe(fileId: number, indexEntry: string): void {
			const slot = this.$refs.frameSlot as HTMLElement | undefined
			if (!slot) return
			slot.innerHTML = ''
			const iframe = buildSandboxedIframe(
				buildAssetUrl(generateUrl(ASSET_PREFIX), fileId, indexEntry),
				this.basename || this.filename || 'package.elpx',
			)
			slot.appendChild(iframe)
			this.iframe = iframe
		},
		attachContentIframe(token: string, indexEntry: string, title: string): void {
			const slot = this.$refs.frameSlot as HTMLElement | undefined
			if (!slot) return
			slot.innerHTML = ''
			const iframe = createContentIframe({
				contentBase: generateUrl(CONTENT_PREFIX),
				token,
				indexEntry,
				title,
			})
			iframe.addEventListener('load', () => {
				// The trusted parent overlays real players over the placeholders
				// the opaque iframe's shim reports (Channel A) and hosts the
				// interactive-video bridge (Channel B).
				startRelay()
				pingEmbeds(iframe)
				attachMedia(iframe)
			})
			slot.appendChild(iframe)
			this.iframe = iframe
			this.relayActive = true
			window.addEventListener('resize', this.onResize)
		},
		onResize(): void {
			reflowOverlays()
		},
		teardown(): void {
			if (this.relayActive) {
				clearOverlays()
				window.removeEventListener('resize', this.onResize)
				this.relayActive = false
			}
			this.iframe?.remove()
			this.iframe = null
			if (this.worker && this.session) {
				void unregisterSession(this.worker, this.session.id)
			}
			this.session = null
			this.worker = null
		},
		handleError(error: unknown): void {
			this.state = 'error'
			if (error instanceof ZipReadError) {
				this.errorTitle = t('exelearning', 'This eXeLearning package cannot be opened.')
				this.errorMessage = this.translateZipError(error)
				this.errorDetail = error.message
				return
			}
			if (error instanceof Error) {
				this.errorTitle = t('exelearning', 'Failed to open eXeLearning package.')
				this.errorMessage = error.message
				this.errorDetail = error.stack ?? ''
				return
			}
			this.errorTitle = t('exelearning', 'Failed to open eXeLearning package.')
			this.errorMessage = String(error)
			this.errorDetail = ''
		},
		translateZipError(error: ZipReadError): string {
			switch (error.code) {
			case 'NOT_A_ZIP':
				return t('exelearning', 'This file is not a valid ZIP archive.')
			case 'PACKAGE_TOO_LARGE':
				return t('exelearning', 'This package is larger than the configured limit.')
			case 'TOO_MANY_ENTRIES':
				return t('exelearning', 'This package contains too many entries.')
			case 'UNSAFE_ENTRY':
				return t('exelearning', 'This package contains an unsafe entry path.')
			case 'CORRUPT':
			default:
				return t('exelearning', 'The package contents are corrupt or cannot be read.')
			}
		},
	},
})
</script>

<style>
.exelearning-viewer {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	min-height: 60vh;
	background: var(--color-main-background, #fff);
}
.exelearning-viewer__frame-slot {
	flex: 1;
	position: relative;
	display: flex;
	overflow: hidden;
}
.exelearning-viewer__iframe {
	flex: 1;
	width: 100%;
	height: 100%;
	border: 0;
	background: #fff;
}
.exelearning-viewer__loading {
	margin: auto;
	text-align: center;
}
.exelearning-viewer__legacy {
	margin: auto;
	max-width: 32rem;
	padding: 2rem;
	text-align: center;
	color: var(--color-text-light, #222);
}
.exelearning-viewer__legacy h2 {
	margin: 0 0 0.75rem;
	font-size: 1.25rem;
}
.exelearning-viewer__legacy p {
	margin: 0 0 1rem;
	line-height: 1.4;
}
.exelearning-viewer__legacy-action {
	margin-top: 0.5rem;
	padding: 0.5rem 1.1rem;
	border: 1px solid var(--color-primary-element, #0a629a);
	background: var(--color-primary-element, #0a629a);
	color: var(--color-primary-element-text, #fff);
	border-radius: 999px;
	cursor: pointer;
	font: inherit;
}
.exelearning-viewer__legacy-action:hover {
	filter: brightness(1.05);
}
.exelearning-viewer__legacy-readonly {
	margin-top: 0.5rem;
	color: var(--color-text-maxcontrast, #777);
	font-size: 0.95rem;
}
.exelearning-viewer__loading .icon-loading {
	display: inline-block;
	width: 32px;
	height: 32px;
	margin-bottom: 0.5rem;
	background-size: contain;
	background-repeat: no-repeat;
	background-position: center;
	background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='32' height='32'><circle cx='16' cy='16' r='12' fill='none' stroke='%23999' stroke-width='3' stroke-dasharray='30 60'><animateTransform attributeName='transform' type='rotate' from='0 16 16' to='360 16 16' dur='1s' repeatCount='indefinite'/></circle></svg>");
}
</style>
