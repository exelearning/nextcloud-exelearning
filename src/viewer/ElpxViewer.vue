<template>
	<div class="exelearning-viewer">
		<div ref="frameSlot" class="exelearning-viewer__frame-slot">
			<div v-if="state === 'loading'" class="exelearning-viewer__loading">
				<span class="icon-loading" aria-hidden="true" />
				<p>{{ status || t('exelearning', 'Opening eXeLearning package…') }}</p>
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

import ViewerError from './ViewerError.vue'

import { loadElpx } from '../elpx/elpx-loader'
import { readPackage, ZipReadError } from '../elpx/zip-reader'
import { validatePackage } from '../elpx/package-validator'
import { ViewerSession } from '../elpx/viewer-session'
import { ensureRuntimeWorker, registerSession, unregisterSession, type RuntimeWorker } from '../elpx/service-worker-client'
import { createPackageIframe } from '../elpx/iframe-renderer'

type State = 'loading' | 'ready' | 'error'

interface Data {
	state: State
	status: string
	errorTitle: string
	errorMessage: string
	errorDetail: string
	session: ViewerSession | null
	worker: RuntimeWorker | null
	iframe: HTMLIFrameElement | null
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
	},
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
				const validation = validatePackage(entries)
				if (!validation.valid || validation.shape.indexEntry === null) {
					throw new Error(validation.error ?? 'The package is missing index.html.')
				}

				this.status = t('exelearning', 'Preparing viewer…')
				const session = ViewerSession.create({
					entries,
					indexEntry: validation.shape.indexEntry,
					filename: loaded.filename || this.filename || this.basename || 'package.elpx',
				})

				const worker = await ensureRuntimeWorker()
				await registerSession(worker, session)

				this.session = session
				this.worker = worker
				this.state = 'ready'
				this.status = ''
				this.$nextTick(() => this.attachIframe(worker, session))
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
		teardown(): void {
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
