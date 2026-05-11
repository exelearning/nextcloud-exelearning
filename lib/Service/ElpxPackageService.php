<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Resolves .elpx files for the current user, applying permission and shape
 * checks so callers can trust the returned File handle.
 */
class ElpxPackageService {
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly PermissionService $permissions,
	) {
	}

	/**
	 * @throws NotFoundException     when the file does not exist or the user
	 *                               cannot see it
	 * @throws NotPermittedException when the file is not a readable .elpx
	 */
	public function getForUserById(string $userId, int $fileId): File {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		if (!$node instanceof File) {
			throw new NotFoundException('File not found');
		}
		$this->assertAllowed($node);
		return $node;
	}

	/**
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getForUserByPath(string $userId, string $path): File {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$node = $userFolder->get($path);
		if (!$node instanceof File) {
			throw new NotFoundException('Not a file');
		}
		$this->assertAllowed($node);
		return $node;
	}

	/**
	 * @throws NotPermittedException
	 */
	private function assertAllowed(Node $node): void {
		if (!($node instanceof File)) {
			throw new NotPermittedException('Not a regular file');
		}
		if (!$this->permissions->isReadable($node)) {
			throw new NotPermittedException('No read permission');
		}
		if (!$this->permissions->isElpxFile($node)) {
			throw new NotPermittedException('Not an eXeLearning package');
		}
		if (!$this->permissions->isWithinSizeLimit($node)) {
			throw new NotPermittedException('Package too large');
		}
	}
}
