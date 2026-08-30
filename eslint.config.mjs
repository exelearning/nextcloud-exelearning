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
			// Keep this toolchain migration behavior-neutral. These rules are
			// newly enforced by the v9 recommended preset and can be adopted in
			// focused follow-up changes instead of reformatting application code
			// as part of the ESLint 10 migration.
			'@stylistic/indent': 'off',
			'@stylistic/max-statements-per-line': 'off',
			'@stylistic/member-delimiter-style': 'off',
			'@stylistic/operator-linebreak': 'off',
			'@stylistic/padded-blocks': 'off',
			'@typescript-eslint/no-use-before-define': 'off',
			'curly': 'off',
			'import-extensions/ban-inline-type-imports': 'off',
			'import-extensions/extensions': 'off',
			'jsdoc/tag-lines': 'off',
			'no-console': 'off',
			'perfectionist/sort-imports': 'off',
			'perfectionist/sort-named-imports': 'off',
			'vue/attribute-hyphenation': 'off',
			'vue/custom-event-name-casing': 'off',
			'vue/first-attribute-linebreak': 'off',
			'vue/new-line-between-multi-line-property': 'off',
			'vue/no-boolean-default': 'off',
			'vue/no-unused-properties': 'off',
			'vue/v-on-event-hyphenation': 'off',

			// `void promise` is the canonical TypeScript way of marking a
			// fire-and-forget call (lifecycle hooks, Service Worker cleanup
			// paths, intentionally swallowed catch bindings). Banning it would
			// force a less explicit alternative.
			'no-void': 'off',
		},
	},
]
