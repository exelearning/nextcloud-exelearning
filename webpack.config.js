const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// The base config derives output names from `process.env.npm_package_name`
// (`nextcloud-exelearning`), but Nextcloud's JS resource loader expects
// `js/<appId>-<name>.js`. Our app ID is `exelearning` (see appinfo/info.xml
// and Util::addInitScript in Application.php), so we override the output
// + publicPath here.
const APP_ID = 'exelearning'

// Two bundles:
//   main   — Viewer handler + Files actions, loaded as an init script.
//   editor — Optional `/apps/exelearning/editor` page (depends on the
//            external eXeLearning static editor being present in js/editor/).
webpackConfig.entry = {
	main: path.resolve(path.join('src', 'main.ts')),
	editor: path.resolve(path.join('src', 'editor', 'editor-page.ts')),
	view: path.resolve(path.join('src', 'view', 'view-page.ts')),
}

webpackConfig.output = {
	...webpackConfig.output,
	publicPath: `/apps/${APP_ID}/js/`,
	filename: `${APP_ID}-[name].js?v=[contenthash]`,
	chunkFilename: `${APP_ID}-[name].js?v=[contenthash]`,
	// The base config sets `clean: true`, which wipes the whole js/
	// directory between builds — including the optional eXeLearning static
	// editor bundle that `make download-editor` drops at js/editor/.
	// Whitelist that subtree (and the hand-written Service Worker, in case
	// someone keeps it under js/) so it survives subsequent rebuilds.
	clean: {
		keep: /^(editor\/|exelearning-sw\.js$)/,
	},
}

// vue-loader fans the TS script block of a `.vue` file out to a virtual
// `.vue?lang=ts` module. ts-loader needs the `appendTsSuffixTo` hint to
// recognize the virtual module as TypeScript; otherwise the SFC's
// `export default Vue.extend(...)` is silently dropped.
for (const rule of webpackConfig.module.rules) {
	if (!rule || !rule.test || !rule.test.toString().includes('tsx')) continue
	if (!Array.isArray(rule.use)) continue
	rule.use = rule.use.map((loader) => {
		if (loader === 'ts-loader' || (typeof loader === 'object' && loader.loader === 'ts-loader')) {
			return {
				loader: 'ts-loader',
				options: {
					appendTsSuffixTo: [/\.vue$/],
					transpileOnly: true,
				},
			}
		}
		return loader
	})
}

module.exports = webpackConfig
