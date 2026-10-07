type OcGlobal = { OC?: { config?: { version?: string } } }

/**
 * Which `@nextcloud/files` API the running Files app reads registrations from.
 *
 * Nextcloud 31 and 32 build their Files app on `@nextcloud/files@3`, which reads
 * the legacy globals (`window._nc_fileactions`, `window._nc_newfilemenu`).
 * Nextcloud 33+ build it on v4, which reads `window._nc_files_scope.v4_0`.
 * Neither reads the other's registry, so one build that supports 31-33 has to
 * register through the API the server actually consumes. Same major-version
 * gate as nextcloud/files_mindmap and nextcloud/collectives. Drop this and
 * the `@nextcloud/files-legacy` dependency once the minimum version is 33.
 *
 * @param version Nextcloud version string, e.g. `32.0.1.2`. Defaults to the
 *                running server's `OC.config.version`.
 */
export function usesLegacyFilesApi(version = (window as OcGlobal).OC?.config?.version ?? ''): boolean {
	return !(Number.parseInt(version.split('.')[0], 10) >= 33)
}
