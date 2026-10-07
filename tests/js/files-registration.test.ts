import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { registerFileActions, toLegacyFileAction } from '../../src/files/actions'
import { usesLegacyFilesApi } from '../../src/files/files-api'
import { registerNewMenuEntry } from '../../src/files/new-menu'

// Registries the Files app reads: v3 (NC 31-32) uses top-level globals,
// v4 (NC 33+) a scoped object captured by @nextcloud/files at import time.
type Globals = {
	OC?: { config?: { version?: string } }
	_nc_fileactions?: { id: string }[]
	_nc_newfilemenu?: { getEntries: () => { id: string }[] }
	_nc_files_scope?: { v4_0?: Record<string, unknown> }
}
const g = window as unknown as Globals

const ACTION_IDS = ['exelearning-view', 'exelearning-edit', 'exelearning-download', 'exelearning-open-as']

/** @param version Server version reported through OC.config. */
function setServerVersion(version: string): void {
	g.OC = { config: { version } }
}

/** IDs registered in the v4 (NC 33+) scope. */
function v4ActionIds(): string[] {
	const map = g._nc_files_scope?.v4_0?.fileActions as Map<string, unknown> | undefined
	return map ? [...map.keys()] : []
}

beforeEach(() => {
	delete g._nc_fileactions
	delete g._nc_newfilemenu
	const scope = g._nc_files_scope?.v4_0 ?? {}
	for (const key of Object.keys(scope)) delete scope[key]
})

afterEach(() => {
	delete g.OC
	vi.restoreAllMocks()
})

describe('usesLegacyFilesApi', () => {
	it.each([
		['31.0.9.1', true],
		['32.0.1.2', true],
		['33.0.0.16', false],
		['34.0.0', false],
		['', true],
	])('version %s -> legacy %s', (version, expected) => {
		expect(usesLegacyFilesApi(version)).toBe(expected)
	})

	it('reads OC.config.version by default', () => {
		setServerVersion('32.0.1')
		expect(usesLegacyFilesApi()).toBe(true)
		setServerVersion('33.0.1')
		expect(usesLegacyFilesApi()).toBe(false)
	})

	it('falls back to the legacy API when OC is not available', () => {
		expect(usesLegacyFilesApi()).toBe(true)
	})
})

describe('registerFileActions', () => {
	it('registers into the v3 registry on Nextcloud 32', () => {
		setServerVersion('32.0.1.2')
		registerFileActions()
		expect(g._nc_fileactions?.map((a) => a.id)).toEqual(ACTION_IDS)
		expect(v4ActionIds()).toEqual([])
	})

	it('registers into the v4 scope on Nextcloud 33', () => {
		setServerVersion('33.0.0.16')
		registerFileActions()
		expect(v4ActionIds()).toEqual(ACTION_IDS)
		expect(g._nc_fileactions).toBeUndefined()
	})
})

describe('toLegacyFileAction', () => {
	const elpx = { fileid: 42, basename: 'course.elpx', mime: 'application/zip', source: 'https://nc/remote.php/dav/files/a/course.elpx' }
	const zip = { fileid: 7, basename: 'other.zip', mime: 'application/zip' }
	const view = { id: 'files' }

	it('maps the positional v3 callbacks onto the v4 context', async () => {
		setServerVersion('32.0.1.2')
		registerFileActions()
		const [viewAction, editAction] = g._nc_fileactions as unknown as ReturnType<typeof toLegacyFileAction>[]

		expect(viewAction.default).toBe('default')
		expect(viewAction.displayName([elpx] as never, view as never)).toBe('Open eXeLearning preview')
		expect(viewAction.enabled?.([elpx] as never, view as never)).toBe(true)
		expect(viewAction.enabled?.([zip] as never, view as never)).toBe(false)
		expect(viewAction.iconSvgInline([elpx] as never, view as never)).toContain('<svg')

		const open = vi.spyOn(window, 'open').mockReturnValue(null)
		await editAction.exec(elpx as never, view as never, '/')
		expect(open).toHaveBeenCalledWith(expect.stringContaining('/apps/exelearning/view?fileId=42&mode=editor'), '_self')
	})

	it('treats an action without enabled() as always enabled', () => {
		const legacy = toLegacyFileAction({
			id: 'x',
			displayName: () => 'x',
			iconSvgInline: () => '<svg/>',
			exec: async () => null,
		})
		expect(legacy.enabled?.([] as never, {} as never)).toBe(true)
	})
})

describe('registerNewMenuEntry', () => {
	it('registers into the v3 new-file menu on Nextcloud 32', () => {
		setServerVersion('32.0.1.2')
		registerNewMenuEntry()
		expect(g._nc_newfilemenu?.getEntries().map((e) => e.id)).toEqual(['exelearning-new-resource'])
		expect(g._nc_files_scope?.v4_0?.newFileMenu).toBeUndefined()
	})

	it('registers into the v4 scope on Nextcloud 33', () => {
		setServerVersion('33.0.0.16')
		registerNewMenuEntry()
		const menu = g._nc_files_scope?.v4_0?.newFileMenu as { getEntries: () => { id: string }[] }
		expect(menu.getEntries().map((e) => e.id)).toEqual(['exelearning-new-resource'])
		expect(g._nc_newfilemenu).toBeUndefined()
	})
})
