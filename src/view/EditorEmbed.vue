<template>
	<div class="exelearning-editor-embed">
		<div v-if="state === 'loading'" class="exelearning-editor-embed__loading">
			<span class="icon-loading" aria-hidden="true" />
			<p>{{ status || t('exelearning', 'Opening the eXeLearning editor…') }}</p>
		</div>
		<div v-else-if="state === 'error'" class="exelearning-editor-embed__error">
			<p>{{ errorMessage }}</p>
		</div>
		<div ref="frameSlot" class="exelearning-editor-embed__slot" />
	</div>
</template>

<script lang="ts">
import { defineComponent } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

import { EditorFrame } from '../editor/editor-frame'
import { loadElpx } from '../elpx/elpx-loader'

type State = 'loading' | 'ready' | 'error'

interface FileMeta {
	id: number
	name: string
	path: string
	mtime: number
	etag: string
	writable: boolean
}

export default defineComponent({
	name: 'EditorEmbed',
	props: {
		file: { type: Object as () => FileMeta, required: true },
		editorIframeUrl: { type: String, required: true },
	},
	data() {
		return {
			state: 'loading' as State,
			status: '',
			errorMessage: '',
			frame: null as EditorFrame | null,
			currentEtag: '',
		}
	},
	async mounted() {
		this.currentEtag = this.file.etag
		try {
			const slot = this.$refs.frameSlot as HTMLElement
			this.frame = new EditorFrame(slot, { editorIframeUrl: this.editorIframeUrl })
			this.status = t('exelearning', 'Loading editor…')
			await this.frame.load()

			this.status = t('exelearning', 'Loading file into the editor…')
			const loaded = await loadElpx({ fileId: this.file.id })
			await this.frame.openFile({ bytes: loaded.bytes, filename: this.file.name })

			this.state = 'ready'
			this.status = ''
		} catch (error) {
			this.state = 'error'
			this.errorMessage = error instanceof Error ? error.message : String(error)
		}
	},
	beforeUnmount() {
		this.frame?.destroy()
		this.frame = null
	},
	methods: {
		t,
		async save(): Promise<void> {
			if (!this.frame) {
				throw new Error(t('exelearning', 'The editor is not ready yet.'))
			}
			const saved = await this.frame.requestSave()
			const url = generateUrl('/apps/exelearning/editor/save')
			const form = new FormData()
			form.append('fileId', String(this.file.id))
			form.append(
				'package',
				new Blob([saved.bytes], { type: 'application/vnd.exelearning.elpx' }),
				this.file.name,
			)
			const response = await axios.post(url, form, {
				headers: this.currentEtag ? { 'If-Match': this.currentEtag } : {},
			})
			// Refresh our cached etag from the server response so subsequent
			// saves do not 412 against a stale tag.
			const data = response.data as { etag?: string } | undefined
			if (data && typeof data.etag === 'string') {
				this.currentEtag = data.etag
			}
		},
	},
})
</script>

<style scoped>
.exelearning-editor-embed {
	position: relative;
	width: 100%;
	height: 100%;
}
.exelearning-editor-embed__slot {
	width: 100%;
	height: 100%;
}
.exelearning-editor-embed__slot :deep(iframe) {
	width: 100%;
	height: 100%;
	border: 0;
	display: block;
}
.exelearning-editor-embed__loading,
.exelearning-editor-embed__error {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-direction: column;
	background: var(--color-main-background, #fff);
	z-index: 1;
}
.exelearning-editor-embed__loading .icon-loading {
	width: 32px;
	height: 32px;
	margin-bottom: 0.5rem;
	background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='32' height='32'><circle cx='16' cy='16' r='12' fill='none' stroke='%23999' stroke-width='3' stroke-dasharray='30 60'><animateTransform attributeName='transform' type='rotate' from='0 16 16' to='360 16 16' dur='1s' repeatCount='indefinite'/></circle></svg>");
}
</style>
