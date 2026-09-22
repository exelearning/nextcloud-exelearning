<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\PermissionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

final class ElpxPackageServiceTest extends TestCase {
	public function testGetForUserByIdReturnsAllowedFile(): void {
		[$service, $folder, $permissions] = $this->service();
		$file = $this->createMock(File::class);

		$folder->expects(self::once())->method('getById')->with(42)->willReturn([$file]);
		$permissions->expects(self::once())->method('isReadable')->with($file)->willReturn(true);
		$permissions->expects(self::once())->method('isWithinSizeLimit')->with($file)->willReturn(true);
		$permissions->expects(self::never())->method('isElpxFile');

		self::assertSame($file, $service->getForUserById('alice', 42));
	}

	public function testGetForUserByIdThrowsWhenNoFileExists(): void {
		[$service, $folder] = $this->service();
		$folder->method('getById')->willReturn([]);

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('File not found');
		$service->getForUserById('alice', 404);
	}

	public function testGetForUserByPathReturnsAllowedFile(): void {
		[$service, $folder, $permissions] = $this->service();
		$file = $this->createMock(File::class);

		$folder->expects(self::once())->method('get')->with('Lessons/demo.elpx')->willReturn($file);
		$permissions->method('isReadable')->willReturn(true);
		$permissions->method('isWithinSizeLimit')->willReturn(true);

		self::assertSame($file, $service->getForUserByPath('alice', 'Lessons/demo.elpx'));
	}

	public function testGetForUserByPathRejectsNonFileNodes(): void {
		[$service, $folder] = $this->service();
		$folder->method('get')->willReturn($this->createMock(Node::class));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Not a file');
		$service->getForUserByPath('alice', 'Lessons');
	}

	public function testRejectsUnreadableFiles(): void {
		[$service, $folder, $permissions] = $this->service();
		$file = $this->createMock(File::class);
		$folder->method('getById')->willReturn([$file]);
		$permissions->method('isReadable')->willReturn(false);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('No read permission');
		$service->getForUserById('alice', 42);
	}

	public function testRejectsOversizedFiles(): void {
		[$service, $folder, $permissions] = $this->service();
		$file = $this->createMock(File::class);
		$folder->method('getById')->willReturn([$file]);
		$permissions->method('isReadable')->willReturn(true);
		$permissions->method('isWithinSizeLimit')->willReturn(false);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Package too large');
		$service->getForUserById('alice', 42);
	}

	/**
	 * @return array{ElpxPackageService, Folder&\PHPUnit\Framework\MockObject\MockObject, PermissionService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function service(): array {
		$root = $this->createMock(IRootFolder::class);
		$folder = $this->createMock(Folder::class);
		$permissions = $this->createMock(PermissionService::class);
		$root->expects(self::once())->method('getUserFolder')->with('alice')->willReturn($folder);

		return [new ElpxPackageService($root, $permissions), $folder, $permissions];
	}
}
