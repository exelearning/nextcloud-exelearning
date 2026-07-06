<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ContentTokenService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ContentTokenService} — the stateless, fileId-bound capability
 * token that gates the cookieless opaque content-serving route.
 */
final class ContentTokenServiceTest extends TestCase {
	private function service(int $now = 1000): ContentTokenService {
		return new ContentTokenService('test-secret-value', static fn (): int => $now);
	}

	public function testRoundTrips(): void {
		$svc = $this->service();
		self::assertSame(42, $svc->verify($svc->mint(42)));
	}

	public function testTokenIsBoundToItsFileId(): void {
		$svc = $this->service();
		$token = $svc->mint(42);
		self::assertSame(42, $svc->verify($token));
		// A token minted for 42 can only ever resolve to 42.
		self::assertNotSame(43, $svc->verify($token));
	}

	public function testTamperedPayloadRejected(): void {
		$svc = $this->service();
		$token = $svc->mint(42);
		$mangled = ($token[0] === 'A' ? 'B' : 'A') . substr($token, 1);
		self::assertNull($svc->verify($mangled));
	}

	public function testExpiredTokenRejected(): void {
		$token = $this->service(1000)->mint(42, 100); // expires at 1100
		self::assertNull($this->service(5000)->verify($token));
	}

	public function testStillValidBeforeExpiry(): void {
		$token = $this->service(1000)->mint(42, 100);
		self::assertSame(42, $this->service(1050)->verify($token));
	}

	public function testGarbageRejected(): void {
		$svc = $this->service();
		self::assertNull($svc->verify(''));
		self::assertNull($svc->verify('not-a-token'));
		self::assertNull($svc->verify('a.b.c'));
		self::assertNull($svc->verify('@@@.@@@'));
	}
}
