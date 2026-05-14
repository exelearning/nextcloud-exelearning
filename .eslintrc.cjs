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
	],
	rules: {
		// `void promise` is the canonical TypeScript way of marking a
		// fire-and-forget call (lifecycle hooks, Service Worker cleanup
		// paths, intentionally swallowed catch bindings). Banning it would
		// force a less explicit alternative.
		'no-void': 'off',
	},
}
