<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

use ZipArchive;

/** Stores complete, expiring editor-preview snapshots outside the web root. */
final class PreviewSnapshotStore {
	/** @var callable():int */
	private $clock;

	public function __construct(
		private readonly string $root,
		private readonly ZipEntryService $paths,
		private readonly int $ttlSeconds = 1800,
		?callable $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function replace(string $ownerId, string $projectId, string $zipPath, ?string $previewId = null): string {
		$this->sweepExpired();
		$id = $previewId ?? $this->uuid();
		if (!$this->validId($id)) {
			throw new \InvalidArgumentException('Invalid preview capability');
		}
		$existing = $this->metadata($id);
		if ($previewId !== null && $existing === null) {
			throw new \RuntimeException('Preview snapshot not found');
		}
		if ($existing !== null && ($existing['ownerId'] !== $ownerId || $existing['projectId'] !== $projectId)) {
			throw new \UnexpectedValueException('Preview snapshot belongs to another project');
		}

		$this->ensureDirectory($this->root);
		$staging = $this->root . '/.staging-' . bin2hex(random_bytes(12));
		$this->ensureDirectory($staging);
		try {
			$this->extract($zipPath, $staging);
			$metadataWritten = file_put_contents($staging . '/.metadata.json', json_encode([
				'ownerId' => $ownerId,
				'projectId' => $projectId,
			], JSON_THROW_ON_ERROR));
			if ($metadataWritten === false || !touch($staging . '/.accessed', ($this->clock)())) {
				throw new \RuntimeException('Cannot write preview metadata');
			}
			$target = $this->root . '/' . $id;
			$backup = $target . '.old-' . bin2hex(random_bytes(6));
			if (is_dir($target) && !rename($target, $backup)) {
				throw new \RuntimeException('Cannot replace preview snapshot');
			}
			if (!rename($staging, $target)) {
				if (is_dir($backup)) {
					rename($backup, $target);
				}
				throw new \RuntimeException('Cannot publish preview snapshot');
			}
			$this->removeTree($backup);
		} catch (\Throwable $error) {
			$this->removeTree($staging);
			throw $error;
		}
		return $id;
	}

	/** @return array{bytes:string,mime:string}|null */
	public function get(string $previewId, string $path): ?array {
		$this->sweepExpired();
		if (!$this->validId($previewId) || $this->metadata($previewId) === null) {
			return null;
		}
		$normalized = $this->paths->normalizeEntry(rawurldecode($path));
		if ($normalized === null || $normalized !== rawurldecode($path) || $this->isReserved($normalized)) {
			return null;
		}
		$root = realpath($this->root . '/' . $previewId);
		$file = realpath($this->root . '/' . $previewId . '/' . $normalized);
		if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
			return null;
		}
		$bytes = file_get_contents($file);
		if ($bytes === false) {
			return null;
		}
		$this->touch($previewId);
		return ['bytes' => $bytes, 'mime' => $this->mime($normalized)];
	}

	public function delete(string $ownerId, string $projectId, string $previewId): bool {
		$metadata = $this->metadata($previewId);
		if ($metadata === null) {
			return false;
		}
		if ($metadata['ownerId'] !== $ownerId || $metadata['projectId'] !== $projectId) {
			throw new \UnexpectedValueException('Preview snapshot belongs to another project');
		}
		$this->removeTree($this->root . '/' . $previewId);
		return true;
	}

	public function sweepExpired(): int {
		if (!is_dir($this->root)) {
			return 0;
		}
		$count = 0;
		foreach (scandir($this->root) ?: [] as $id) {
			if (!$this->validId($id)) {
				continue;
			}
			$accessed = @filemtime($this->root . '/' . $id . '/.accessed');
			if ($accessed === false || ($this->clock)() - $accessed > $this->ttlSeconds) {
				$this->removeTree($this->root . '/' . $id);
				$count++;
			}
		}
		return $count;
	}

	private function extract(string $zipPath, string $target): void {
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new \InvalidArgumentException('Invalid preview ZIP');
		}
		try {
			if ($zip->numFiles > ZipEntryService::MAX_ENTRIES) {
				throw new \LengthException('Preview ZIP contains too many files');
			}
			$total = 0;
			$hasIndex = false;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);
				$stat = $zip->statIndex($i);
				if (!is_string($name) || !is_array($stat)) {
					throw new \InvalidArgumentException('Invalid preview ZIP entry');
				}
				if ($this->paths->normalizeEntry($name) !== $name) {
					throw new \InvalidArgumentException('Unsafe preview ZIP path');
				}
				if (str_ends_with($name, '/')) {
					continue;
				}
				if ($this->isReserved($name)) {
					throw new \InvalidArgumentException('Reserved preview ZIP path');
				}
				$operationsSystem = 0;
				$attributes = 0;
				if ($zip->getExternalAttributesIndex($i, $operationsSystem, $attributes)
					&& $operationsSystem === ZipArchive::OPSYS_UNIX
					&& (($attributes >> 16) & 0xf000) === 0xa000) {
					throw new \InvalidArgumentException('Preview ZIP contains a symbolic link');
				}
				$total += (int)($stat['size'] ?? 0);
				if ($total > 100 * 1024 * 1024) {
					throw new \LengthException('Preview ZIP is too large');
				}
				$hasIndex = $hasIndex || $name === 'index.html';
			}
			if (!$hasIndex || !$zip->extractTo($target)) {
				throw new \InvalidArgumentException('Preview ZIP must contain index.html');
			}
		} finally {
			$zip->close();
		}
	}

	/** @return array{ownerId:string,projectId:string}|null */
	private function metadata(string $id): ?array {
		if (!$this->validId($id)) {
			return null;
		}
		$data = @file_get_contents($this->root . '/' . $id . '/.metadata.json');
		$value = is_string($data) ? json_decode($data, true) : null;
		return is_array($value) && isset($value['ownerId'], $value['projectId']) ? $value : null;
	}

	private function touch(string $id): void {
		touch($this->root . '/' . $id . '/.accessed', ($this->clock)());
	}

	private function validId(string $id): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1;
	}

	private function isReserved(string $path): bool {
		return $path === '.metadata.json' || $path === '.accessed';
	}

	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);
		return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
	}

	private function mime(string $path): string {
		return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
			'html', 'htm' => 'text/html; charset=utf-8', 'xhtml' => 'application/xhtml+xml',
			'xml' => 'application/xml', 'svg' => 'image/svg+xml', 'css' => 'text/css; charset=utf-8',
			'js', 'mjs' => 'application/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
			'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
			'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
			'pdf' => 'application/pdf', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
			default => 'application/octet-stream',
		};
	}

	private function ensureDirectory(string $path): void {
		if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
			throw new \RuntimeException('Cannot create preview directory');
		}
	}

	private function removeTree(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		foreach (new \FilesystemIterator($path) as $entry) {
			$entry->isDir() && !$entry->isLink() ? $this->removeTree($entry->getPathname()) : @unlink($entry->getPathname());
		}
		@rmdir($path);
	}
}
