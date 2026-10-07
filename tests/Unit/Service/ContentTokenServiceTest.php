<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\ContentTokenService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ContentTokenService} — the stateless, fileId+userId-bound
 * capability token that gates the cookieless opaque content-serving route.
 */
final class ContentTokenServiceTest extends TestCase {
	private function service(int $now = 1000): ContentTokenService {
		return new ContentTokenService('test-secret-value', static fn (): int => $now);
	}

	public function testRoundTripsFileIdAndUser(): void {
		$svc = $this->service();
		self::assertSame([42, 'alice'], $svc->verify($svc->mint(42, 'alice')));
	}

	public function testUserIdWithSpecialCharsSurvives(): void {
		$svc = $this->service();
		$uid = 'user.with-dots_and@example';
		self::assertSame([7, $uid], $svc->verify($svc->mint(7, $uid)));
	}

	public function testTamperedPayloadRejected(): void {
		$svc = $this->service();
		$token = $svc->mint(42, 'alice');
		$mangled = ($token[0] === 'A' ? 'B' : 'A') . substr($token, 1);
		self::assertNull($svc->verify($mangled));
	}

	public function testExpiredTokenRejected(): void {
		$token = $this->service(1000)->mint(42, 'alice', 100); // expires at 1100
		self::assertNull($this->service(5000)->verify($token));
	}

	public function testStillValidBeforeExpiry(): void {
		$token = $this->service(1000)->mint(42, 'alice', 100);
		self::assertSame([42, 'alice'], $this->service(1050)->verify($token));
	}

	public function testSignedPayloadWithWrongShapeIsRejected(): void {
		self::assertNull($this->service()->verify($this->signedToken('42.2000')));
	}

	public function testSignedPayloadRequiresNumericFileAndExpiry(): void {
		$encodedUser = $this->b64('alice');

		self::assertNull($this->service()->verify($this->signedToken('file.2000.' . $encodedUser)));
		self::assertNull($this->service()->verify($this->signedToken('42.expiry.' . $encodedUser)));
	}

	public function testSignedPayloadRejectsEmptyUser(): void {
		self::assertNull($this->service()->verify($this->signedToken('42.2000.')));
	}

	public function testGarbageRejected(): void {
		$svc = $this->service();
		self::assertNull($svc->verify(''));
		self::assertNull($svc->verify('not-a-token'));
		self::assertNull($svc->verify('a.b.c'));
		self::assertNull($svc->verify('@@@.@@@'));
	}

	private function signedToken(string $payload): string {
		$signature = hash_hmac(
			'sha256',
			$payload,
			'test-secret-value|exelearning-content-v1',
			true,
		);
		return $this->b64($payload) . '.' . $this->b64($signature);
	}

	private function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

}
