import { defineConfig } from 'vitest/config'
import path from 'node:path'

export default defineConfig({
	test: {
		globals: true,
		environment: 'happy-dom',
		include: ['tests/js/**/*.test.ts', 'src/**/*.test.ts'],
		coverage: {
			provider: 'v8',
			reporter: ['text', 'lcov'],
			reportsDirectory: 'coverage/js',
			include: [
				'src/editor/editor-frame.ts',
				'src/editor/editor-messages.ts',
				'src/elpx/asset-map.ts',
				'src/elpx/elpx-loader.ts',
				'src/elpx/iframe-renderer.ts',
				'src/elpx/package-validator.ts',
				'src/elpx/paths.ts',
				'src/elpx/service-worker-client.ts',
				'src/elpx/viewer-session.ts',
				'src/elpx/zip-reader.ts',
				'src/files/mime.ts',
			],
			thresholds: {
				lines: 90,
				functions: 90,
				branches: 90,
				statements: 90,
			},
		},
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})
