<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ElpxPackageService;
use OCA\ExeLearning\Service\LegacyFileMigrationService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyFileMigrationServiceTest extends TestCase {
	private ElpxPackageService $packages;
	private LegacyFileMigrationService $service;

	protected function setUp(): void {
		$this->packages = $this->createMock(ElpxPackageService::class);
		$this->service = new LegacyFileMigrationService($this->packages);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function nonLegacyNames(): iterable {
		yield 'modern extension' => ['lesson.elpx'];
		yield 'modern uppercase extension' => ['lesson.ELPX'];
		yield 'unrelated archive' => ['lesson.zip'];
	}

	#[DataProvider('nonLegacyNames')]
	public function testLeavesNonLegacyNamesUntouched(string $name): void {
		$file = $this->file($name);
		$file->expects(self::never())->method('getParent');

		self::assertSame($file, $this->service->migrate($file, 'alice', 42));
	}

	public function testLeavesFileUntouchedWhenParentCannotBeResolved(): void {
		$file = $this->file('Lesson.elp');
		$file->method('getParent')->willThrowException(new NotFoundException('gone'));

		self::assertSame($file, $this->service->migrate($file, 'alice', 42));
	}

	public function testMigratesToModernExtensionAndRefetchesFile(): void {
		$parent = $this->parent('/Lessons');
		$parent->method('nodeExists')->with('Lesson.elpx')->willReturn(false);
		$file = $this->file('Lesson.elp');
		$file->method('getParent')->willReturn($parent);
		$file->expects(self::once())->method('move')->with('/Lessons/Lesson.elpx');
		$renamed = $this->file('Lesson.elpx');
		$this->packages->expects(self::once())
			->method('getForUserById')
			->with('alice', 42)
			->willReturn($renamed);

		self::assertSame($renamed, $this->service->migrate($file, 'alice', 42));
	}

	public function testChoosesFirstAvailableCollisionSuffix(): void {
		$parent = $this->parent('/Lessons');
		$parent->method('nodeExists')->willReturnCallback(
			static fn (string $name): bool => in_array($name, ['Lesson.elpx', 'Lesson (2).elpx'], true),
		);
		$file = $this->file('Lesson.elp');
		$file->method('getParent')->willReturn($parent);
		$file->expects(self::once())->method('move')->with('/Lessons/Lesson (3).elpx');
		$this->packages->method('getForUserById')->willReturn($file);

		self::assertSame($file, $this->service->migrate($file, 'alice', 42));
	}

	public function testStopsWhenEveryCandidateIsOccupied(): void {
		$parent = $this->parent('/Lessons');
		$parent->method('nodeExists')->willReturn(true);
		$file = $this->file('Lesson.elp');
		$file->method('getParent')->willReturn($parent);
		$file->expects(self::never())->method('move');

		self::assertSame($file, $this->service->migrate($file, 'alice', 42));
	}

	/**
	 * @return iterable<string, array{\Throwable}>
	 */
	public static function moveFailures(): iterable {
		yield 'permission denied' => [new NotPermittedException('denied')];
		yield 'invalid path' => [new InvalidPathException('invalid')];
	}

	#[DataProvider('moveFailures')]
	public function testMoveFailuresAreBestEffort(\Throwable $error): void {
		$parent = $this->parent('/Lessons');
		$parent->method('nodeExists')->willReturn(false);
		$file = $this->file('Lesson.elp');
		$file->method('getParent')->willReturn($parent);
		$file->method('move')->willThrowException($error);
		$this->packages->expects(self::never())->method('getForUserById');

		self::assertSame($file, $this->service->migrate($file, 'alice', 42));
	}

	/**
	 * @return iterable<string, array{\Throwable}>
	 */
	public static function refetchFailures(): iterable {
		yield 'not found after move' => [new NotFoundException('gone')];
		yield 'not permitted after move' => [new NotPermittedException('denied')];
	}

	#[DataProvider('refetchFailures')]
	public function testRefetchFailuresReturnOriginalHandle(\Throwable $error): void {
		$parent = $this->parent('/Lessons');
		$parent->method('nodeExists')->willReturn(false);
		$file = $this->file('Lesson.elp');
		$file->method('getParent')->willReturn($parent);
		$this->packages->method('getForUserById')->willThrowException($error);

		self::assertSame($file, $this->service->migrate($file, 'alice', 42));
	}

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function parent(string $path): Folder {
		$parent = $this->createMock(Folder::class);
		$parent->method('getPath')->willReturn($path);
		return $parent;
	}
}
