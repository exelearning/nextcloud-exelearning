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
		// The vendored eXe-core external-media bundle: built artifacts owned by
		// upstream (exelearning/exelearning ADR-2199-12), verified byte for byte against the manifest core
		// published. They are minified output, not source, so our lint rules do not
		// apply — and a local "fix" here would be overwritten on the next re-vendor.
		'src/embed/exe_external_media/',
	],
	rules: {
		// `void promise` is the canonical TypeScript way of marking a
		// fire-and-forget call (lifecycle hooks, Service Worker cleanup
		// paths, intentionally swallowed catch bindings). Banning it would
		// force a less explicit alternative.
		'no-void': 'off',
	},
}
