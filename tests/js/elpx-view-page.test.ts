import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => path }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app: string, text: string) => text }))

const { default: ElpxViewPage } = await import('../../src/view/ElpxViewPage.vue')

const embedSave = vi.fn<() => Promise<void>>()
const EditorEmbedStub = defineComponent({
	name: 'EditorEmbed',
	methods: { save: () => embedSave() },
	template: '<div />',
})

function mountEditor() {
	return mount(ElpxViewPage, {
		props: {
			file: { id: 7, name: 'lesson.elpx', path: '/lesson.elpx', mtime: 0, etag: 'e', writable: true },
			editorAvailable: true,
			initialMode: 'editor',
		},
		global: { stubs: { EditorEmbed: EditorEmbedStub, ElpxViewer: true } },
	})
}

let assign: ReturnType<typeof vi.fn>
let alert: ReturnType<typeof vi.fn>

beforeEach(() => {
	embedSave.mockReset()
	assign = vi.fn()
	vi.stubGlobal('location', { ...window.location, assign, href: 'http://localhost/apps/exelearning/view' })
	alert = vi.fn()
	vi.stubGlobal('alert', alert)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('ElpxViewPage save actions', () => {
	it('Save writes the package and keeps the editor open', async () => {
		embedSave.mockResolvedValue()
		const page = mountEditor()

		await page.get('[data-action="save"]').trigger('click')
		await flushPromises()

		expect(embedSave).toHaveBeenCalledTimes(1)
		expect(assign).not.toHaveBeenCalled()
		expect(page.findComponent(EditorEmbedStub).exists()).toBe(true)
	})

	it('Save & Close saves first and returns to Files only afterwards', async () => {
		let finishSave: () => void = () => undefined
		embedSave.mockReturnValue(new Promise<void>((resolve) => { finishSave = resolve }))
		const page = mountEditor()

		await page.get('[data-action="save-and-close"]').trigger('click')
		expect(embedSave).toHaveBeenCalledTimes(1)
		expect(assign).not.toHaveBeenCalled()

		finishSave()
		await flushPromises()

		expect(assign).toHaveBeenCalledWith('/apps/files')
	})

	it('Save & Close stays in the editor when saving fails', async () => {
		embedSave.mockRejectedValue(new Error('412 Precondition Failed'))
		const page = mountEditor()

		await page.get('[data-action="save-and-close"]').trigger('click')
		await flushPromises()

		expect(assign).not.toHaveBeenCalled()
		expect(alert).toHaveBeenCalledWith('Save failed: {error}')
		expect(page.get('[data-action="save"]').attributes('disabled')).toBeUndefined()
	})

	it('ignores repeated clicks while a save is in flight', async () => {
		let finishSave: () => void = () => undefined
		embedSave.mockReturnValue(new Promise<void>((resolve) => { finishSave = resolve }))
		const page = mountEditor()
		const vm = page.vm as unknown as { save: () => Promise<boolean>, saveAndClose: () => Promise<void> }

		const first = vm.save()
		await page.vm.$nextTick()
		expect(page.get('[data-action="save"]').attributes('disabled')).toBeDefined()
		expect(page.get('[data-action="save-and-close"]').attributes('disabled')).toBeDefined()
		// Bypass the disabled buttons: the guard must hold for Ctrl+S too.
		expect(await vm.save()).toBe(false)
		await vm.saveAndClose()

		finishSave()
		expect(await first).toBe(true)
		expect(embedSave).toHaveBeenCalledTimes(1)
		expect(assign).not.toHaveBeenCalled()
	})
})
