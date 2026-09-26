import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string, params?: Record<string, unknown>) =>
		path.replace(/\{(\w+)\}/g, (_, key: string) => String(params?.[key])),
}))

const { loadElpx } = await import('../../src/elpx/elpx-loader')

function respond(headers: Record<string, string> = {}) {
	get.mockResolvedValueOnce({ data: new ArrayBuffer(3), headers })
}

describe('loadElpx', () => {
	beforeEach(() => get.mockReset())

	it('requires a file id or a path', async () => {
		await expect(loadElpx({})).rejects.toThrow('Either fileId or path')
		await expect(loadElpx({ path: '' })).rejects.toThrow('Either fileId or path')
	})

	it('loads by file id and reads filename and etag from the headers', async () => {
		respond({ 'content-disposition': 'attachment; filename*=UTF-8\'\'Lecci%C3%B3n%201.elpx', etag: '"abc"' })

		const loaded = await loadElpx({ fileId: 7 })

		expect(get).toHaveBeenCalledWith('/apps/exelearning/package/by-file-id/7', { responseType: 'arraybuffer' })
		expect(loaded).toMatchObject({ filename: 'Lección 1.elpx', etag: '"abc"' })
	})

	it('loads by path and accepts a plain quoted filename', async () => {
		respond({ 'content-disposition': 'attachment; filename="plain.elpx"' })

		const loaded = await loadElpx({ path: 'Docs/x.elpx' })

		expect(get).toHaveBeenCalledWith('/apps/exelearning/package/by-path', {
			responseType: 'arraybuffer',
			params: { path: 'Docs/x.elpx' },
		})
		expect(loaded.filename).toBe('plain.elpx')
		expect(loaded).not.toHaveProperty('etag')
	})

	it('keeps an undecodable filename verbatim', async () => {
		respond({ 'content-disposition': 'attachment; filename*=UTF-8\'\'bad%E0.elpx' })

		expect((await loadElpx({ fileId: 1 })).filename).toBe('bad%E0.elpx')
	})

	it('derives a filename when the server sends no Content-Disposition', async () => {
		respond()
		respond()
		respond({ 'content-disposition': 'inline' })

		expect((await loadElpx({ path: 'Docs/lesson.elpx' })).filename).toBe('lesson.elpx')
		expect((await loadElpx({ path: 'top.elpx' })).filename).toBe('top.elpx')
		expect((await loadElpx({ fileId: 9 })).filename).toBe('package-9.elpx')
	})
})
