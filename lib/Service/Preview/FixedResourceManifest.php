<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service\Preview;

/**
 * Resolver for the **fixed installation-resource** layer (layer 1) of the
 * preview serving contract.
 *
 * The fixed layer is served straight from the host's installed static editor
 * distribution and is enumerated by a build manifest
 * (`bundles/preview-fixed-resources.json`) that eXe core emits into the
 * distribution. It is the ONLY authority for what the serving route may resolve
 * outside a session:
 *
 *  - {@see self::has()} — is $id enumerated by the manifest? (revision validation)
 *  - {@see self::get()} — read the file at `resources[$id].path` under the
 *    distribution root, with a containment check. Never path-arithmetic on
 *    client input: only the server-controlled manifest `path` reaches the disk.
 *
 * Provenance, not name: the manifest lists base (repo-shipped) libraries,
 * base iDevice runtimes, base theme files, PDF.js, content CSS, the logo and
 * global fonts. Custom themes, user-installed iDevices and anything embedded in
 * an `.elpx` are never listed — they ride the session layers.
 *
 * When the manifest is ABSENT (e.g. an older bundled editor that predates it),
 * the fixed layer is simply disabled: {@see self::has()} is always false, so any
 * `fixedRefs` in a revision is rejected with `422 unknown-fixed-resources` and
 * the client demotes those refs into the session layers. This is never fatal.
 *
 * OCP-free: the distribution root is an injected filesystem path and I/O goes
 * through injected callables, so the whole resolver is unit-testable against a
 * throwaway root (which is also how the conformance vectors materialize their
 * fixed resources).
 */
final class FixedResourceManifest {
	private const MANIFEST_RELATIVE_PATH = 'bundles/preview-fixed-resources.json';

	/** @var callable(string):(string|false) */
	private $reader;

	/** @var callable(string):bool */
	private $exists;

	/**
	 * Lazily-parsed `id => ['path' => string, 'size' => int]` map, or null until
	 * first access. An empty map means "manifest absent or unreadable".
	 *
	 * @var array<string,array{path:string,size:int}>|null
	 */
	private ?array $resources = null;

	/**
	 * @param string $distRoot Absolute path to the installed editor distribution
	 *                         root (e.g. `<app>/js/editor`). Manifest `path`
	 *                         values are resolved relative to it.
	 * @param (callable(string):(string|false))|null $reader File reader (defaults
	 *                                                       to file_get_contents).
	 * @param (callable(string):bool)|null $exists is_file probe (defaults to is_file).
	 */
	public function __construct(
		private readonly string $distRoot,
		?callable $reader = null,
		?callable $exists = null,
	) {
		$this->reader = $reader ?? static fn (string $path): string|false => @file_get_contents($path);
		$this->exists = $exists ?? static fn (string $path): bool => is_file($path);
	}

	/** Whether $id is enumerated by the manifest. */
	public function has(string $id): bool {
		return isset($this->load()[$id]);
	}

	/**
	 * Read the file backing $id, or null when the id is unknown, the file is
	 * missing/unreadable, or the resolved path escapes the distribution root.
	 *
	 * @return array{bytes:string,size:int}|null
	 */
	public function get(string $id): ?array {
		$entry = $this->load()[$id] ?? null;
		if ($entry === null) {
			return null;
		}
		$resolved = $this->resolveContained($entry['path']);
		if ($resolved === null || !($this->exists)($resolved)) {
			return null;
		}
		$bytes = ($this->reader)($resolved);
		if ($bytes === false) {
			return null;
		}
		return ['bytes' => $bytes, 'size' => strlen($bytes)];
	}

	/**
	 * Parse the manifest once. Any structural problem (missing file, invalid
	 * JSON, wrong shape) yields an empty map — the fixed layer is disabled, never
	 * an error.
	 *
	 * @return array<string,array{path:string,size:int}>
	 */
	private function load(): array {
		if ($this->resources !== null) {
			return $this->resources;
		}
		$this->resources = [];
		$manifestPath = $this->distRoot . '/' . self::MANIFEST_RELATIVE_PATH;
		if (!($this->exists)($manifestPath)) {
			return $this->resources;
		}
		$raw = ($this->reader)($manifestPath);
		if ($raw === false) {
			return $this->resources;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !isset($decoded['resources']) || !is_array($decoded['resources'])) {
			return $this->resources;
		}
		foreach ($decoded['resources'] as $id => $entry) {
			if (!is_string($id) || !is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
				continue;
			}
			$this->resources[$id] = [
				'path' => $entry['path'],
				'size' => isset($entry['size']) && is_int($entry['size']) ? $entry['size'] : 0,
			];
		}
		return $this->resources;
	}

	/**
	 * Resolve a manifest-declared path under the distribution root and confirm
	 * it stays contained (rejects `..`, absolute paths and symlink escapes).
	 * Returns the absolute path or null.
	 */
	private function resolveContained(string $relative): ?string {
		if (str_contains($relative, "\0")) {
			return null;
		}
		$normalized = PreviewPolicy::normalizePath($relative);
		if ($normalized === null) {
			return null;
		}
		$candidate = $this->distRoot . '/' . $normalized;
		$realRoot = realpath($this->distRoot);
		$realCandidate = realpath($candidate);
		if ($realRoot === false || $realCandidate === false) {
			return null;
		}
		$prefix = rtrim($realRoot, '/') . '/';
		if ($realCandidate !== $realRoot && !str_starts_with($realCandidate, $prefix)) {
			return null;
		}
		return $realCandidate;
	}
}
