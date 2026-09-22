import { existsSync, readdirSync, rmSync } from 'node:fs'
import { resolve } from 'node:path'

import { createAppConfig } from '@nextcloud/vite-config'
import type { Plugin } from 'vite'

/**
 * Clear generated frontend assets without deleting the optional static editor.
 *
 * @nextcloud/vite-config normally empties js/ before each build, but this app
 * stores the separately downloaded eXeLearning editor in js/editor/. Keep that
 * directory intact while removing stale entry files and chunks from prior
 * builds.
 */
function cleanGeneratedJs(): Plugin {
	return {
		name: 'exelearning-clean-generated-js',
		buildStart() {
			const outputDir = resolve('js')
			if (!existsSync(outputDir)) {
				return
			}

			for (const entry of readdirSync(outputDir, { withFileTypes: true })) {
				if (entry.name === 'editor' || entry.name === '.gitkeep') {
					continue
			}
				rmSync(resolve(outputDir, entry.name), { recursive: true, force: true })
			}
		},
	}
}

export default createAppConfig(
	{
		main: resolve('src', 'main.ts'),
		editor: resolve('src', 'editor', 'editor-page.ts'),
		view: resolve('src', 'view', 'view-page.ts'),
	},
	{
		// Match the previous style-loader behavior: controllers only register
		// the JS entry points, so component styles must travel with the scripts.
		inlineCSS: true,

		// The default cleaner would remove js/editor/, which is an optional
		// separately downloaded runtime asset. Our plugin above performs the
		// narrower cleanup this repository needs.
		emptyOutputDirectory: false,

		// Webpack's asset/inline rule embedded imported images and fonts. Keep
		// that behavior so Vite cannot emit build assets into the app's static
		// img/ directory.
		config: {
			plugins: [cleanGeneratedJs()],
			build: {
				assetsInlineLimit: Number.MAX_SAFE_INTEGER,
			},
		},

		// Distribution packages already exclude source maps and did not ship
		// generated per-bundle license sidecars under the Webpack build.
		extractLicenseInformation: false,
	},
)
