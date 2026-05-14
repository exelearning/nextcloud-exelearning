<template>
	<div class="exelearning-view-page">
		<header class="exelearning-view-page__toolbar">
			<a class="exelearning-view-page__back"
				:href="filesUrl"
				:title="t('exelearning', 'Back to Files')">
				<span aria-hidden="true">←</span>
			</a>
			<span class="exelearning-view-page__filename" :title="displayName">
				{{ displayName || t('exelearning', 'Loading…') }}
			</span>
			<span v-if="saveStatus" class="exelearning-view-page__status">{{ saveStatus }}</span>
			<button v-if="canShowEditButton"
				type="button"
				class="exelearning-view-page__action"
				:title="t('exelearning', 'Edit with eXeLearning')"
				@click="switchToEditor">
				<svg xmlns="http://www.w3.org/2000/svg"
					viewBox="0 0 24 24"
					width="20"
					height="20"
					fill="currentColor">
					<path d="M20.71 7.04c.39-.39.39-1.04 0-1.41l-2.34-2.34c-.37-.39-1.02-.39-1.41 0l-1.84 1.83 3.75 3.75M3 17.25V21h3.75L17.81 9.93l-3.75-3.75L3 17.25z" />
				</svg>
				<span>{{ t('exelearning', 'Edit') }}</span>
			</button>
			<button v-else-if="canShowSaveButton"
				type="button"
				class="exelearning-view-page__action"
				:disabled="saving"
				:title="t('exelearning', 'Save to Nextcloud')"
				@click="save">
				<svg xmlns="http://www.w3.org/2000/svg"
					viewBox="0 0 24 24"
					width="20"
					height="20"
					fill="currentColor">
					<path d="M15,9H5V5H15M12,19A3,3 0 0,1 9,16A3,3 0 0,1 12,13A3,3 0 0,1 15,16A3,3 0 0,1 12,19M17,3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V7L17,3Z" />
				</svg>
				<span>{{ saving ? t('exelearning', 'Saving…') : t('exelearning', 'Save') }}</span>
			</button>
		</header>
		<div class="exelearning-view-page__body">
			<ElpxViewer v-if="file && mode === 'preview'"
				:key="'preview-' + file.id"
				:fileid="file.id"
				:filename="file.path || file.name"
				:basename="file.name"
				:can-migrate="canEdit"
				@migrate-in-editor="switchToEditor" />
			<EditorEmbed v-else-if="file && mode === 'editor'"
				ref="editor"
				:key="'editor-' + file.id"
				:file="file"
				:editor-iframe-url="editorIframeUrl"
				@file-renamed="onFileRenamed" />
			<div v-else class="exelearning-view-page__missing">
				<p>{{ t('exelearning', 'No file selected.') }}</p>
				<a :href="filesUrl">{{ t('exelearning', 'Back to Files') }}</a>
			</div>
		</div>
	</div>
</template>

<script lang="ts">
import { defineComponent } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

import ElpxViewer from '../viewer/ElpxViewer.vue'
import EditorEmbed from './EditorEmbed.vue'

interface InitialFile {
	id: number
	name: string
	path: string
	mtime: number
	etag: string
	writable: boolean
}

type Mode = 'preview' | 'editor'

export default defineComponent({
	name: 'ElpxViewPage',
	components: { ElpxViewer, EditorEmbed },
	props: {
		file: { type: Object as () => InitialFile | null, default: null },
		editorAvailable: { type: Boolean, default: false },
		editorIframeUrl: { type: String, default: '' },
		initialMode: { type: String as () => Mode, default: 'preview' },
	},
	data() {
		return {
			mode: this.initialMode as Mode,
			saving: false,
			saveStatus: '',
			// Mirror of `file.name` we are allowed to mutate locally — Vue's
			// `vue/no-mutating-props` rule disallows touching props directly,
			// and `EditorController::save` may have renamed `.elp` → `.elpx`
			// underneath us.
			currentName: this.file?.name ?? '',
		}
	},
	computed: {
		filesUrl(): string {
			return generateUrl('/apps/files')
		},
		canEdit(): boolean {
			return Boolean(this.file && this.file.writable && this.editorAvailable)
		},
		canShowEditButton(): boolean {
			return this.mode === 'preview' && this.canEdit
		},
		canShowSaveButton(): boolean {
			return this.mode === 'editor' && Boolean(this.file && this.file.writable)
		},
		displayName(): string {
			return this.currentName || this.file?.name || ''
		},
	},
	methods: {
		t,
		switchToEditor(): void {
			this.mode = 'editor'
			this.saveStatus = ''
			// Reflect the new mode in the URL so reloading the page keeps the
			// editor open instead of bouncing back to the preview.
			if (window.history && this.file) {
				const url = new URL(window.location.href)
				url.searchParams.set('mode', 'editor')
				window.history.replaceState(null, '', url.toString())
			}
		},
		onFileRenamed(newName: string): void {
			// Server migrated `.elp` → `.elpx` on save. Update our local
			// mirror so the toolbar reflects the new name; fileId is
			// preserved by Nextcloud across the rename so the URL stays
			// valid without any further work.
			if (!newName || newName === this.currentName) {
				return
			}
			this.currentName = newName
			const message = t('exelearning', 'Saved as {name}', { name: newName })
			this.saveStatus = message
			window.setTimeout(() => {
				if (this.saveStatus === message) {
					this.saveStatus = ''
				}
			}, 4000)
		},
		async save(): Promise<void> {
			const embed = this.$refs.editor as InstanceType<typeof EditorEmbed> | undefined
			if (!embed) return
			this.saving = true
			this.saveStatus = t('exelearning', 'Saving…')
			try {
				await embed.save()
				this.saveStatus = t('exelearning', 'Saved')
				window.setTimeout(() => {
					if (this.saveStatus === t('exelearning', 'Saved')) {
						this.saveStatus = ''
					}
				}, 3000)
			} catch (error) {
				this.saveStatus = ''
				const message = error instanceof Error ? error.message : String(error)
				window.alert(t('exelearning', 'Save failed: {error}', { error: message }))
			} finally {
				this.saving = false
			}
		},
	},
})
</script>

<style>
/* Fill the viewport BELOW Nextcloud's top header so the user keeps the
 * usual app switcher, search, avatar and notifications. We anchor with
 * `top: var(--header-height)` instead of `inset: 0` so the Nextcloud bar
 * (typically 50px tall) stays visible. */
.exelearning-view-page {
	position: fixed;
	top: var(--header-height, 50px);
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	background: var(--color-main-background, #fff);
	z-index: 1000;
}
.exelearning-view-page__toolbar {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 1rem;
	border-bottom: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	flex: 0 0 auto;
}
.exelearning-view-page__back {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 50%;
	font-size: 1.25rem;
	text-decoration: none;
	color: var(--color-text-light, #222);
}
.exelearning-view-page__back:hover {
	background: var(--color-background-hover, #ececec);
}
.exelearning-view-page__filename {
	flex: 1;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.exelearning-view-page__status {
	color: var(--color-text-maxcontrast, #777);
	font-size: 0.9rem;
}
.exelearning-view-page__action {
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.4rem 0.9rem;
	border: 1px solid var(--color-primary-element, #0a629a);
	background: var(--color-primary-element, #0a629a);
	color: var(--color-primary-element-text, #fff);
	border-radius: 999px;
	cursor: pointer;
	font: inherit;
}
.exelearning-view-page__action[disabled] {
	opacity: 0.6;
	cursor: not-allowed;
}
.exelearning-view-page__action:hover:not([disabled]) { filter: brightness(1.05); }
.exelearning-view-page__body {
	flex: 1;
	display: flex;
	overflow: hidden;
}
.exelearning-view-page__body > * { flex: 1; }
.exelearning-view-page__missing {
	margin: auto;
	text-align: center;
	color: var(--color-text-maxcontrast, #777);
}
</style>
