import { recommended } from '@nextcloud/eslint-config'

export default [
	{
		ignores: [
			'build/**',
			'coverage/**',
			'dist/**',
			'exelearning/**',
			'js/**',
			'node_modules/**',
			'vendor/**',
		],
	},
	...recommended,
	{
		rules: {
			// `void promise` is the canonical TypeScript way of marking a
			// fire-and-forget call (lifecycle hooks, Service Worker cleanup
			// paths, intentionally swallowed catch bindings). Banning it would
			// force a less explicit alternative.
			'no-void': 'off',
		},
	},
]
