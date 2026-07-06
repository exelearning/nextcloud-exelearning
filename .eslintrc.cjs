/**
 * ESLint configuration for nextcloud-exelearning.
 *
 * Uses the official `@nextcloud/eslint-config` TypeScript preset so this
 * app stays aligned with the lint rules `nextcloud/server` and the rest
 * of the Nextcloud ecosystem use. Biome (see biome.json) handles the
 * fast .ts/.js style pass; ESLint owns Vue single-file components and
 * the framework-aware rules that Biome does not provide.
 */
module.exports = {
	root: true,
	extends: ['@nextcloud/eslint-config/typescript'],
	ignorePatterns: [
		'build/',
		'coverage/',
		'dist/',
		'exelearning/',
		'js/',
		'node_modules/',
		'vendor/',
		// Centrally-maintained, byte-synced eXe-core embed/media bridge mirrors
		// (see scripts/check-embed-sync.mjs). They keep upstream's code style and
		// module wrapper, so our lint rules do not apply to them.
		'src/embed/exe_*.js',
	],
	rules: {
		// `void promise` is the canonical TypeScript way of marking a
		// fire-and-forget call (lifecycle hooks, Service Worker cleanup
		// paths, intentionally swallowed catch bindings). Banning it would
		// force a less explicit alternative.
		'no-void': 'off',
	},
}
