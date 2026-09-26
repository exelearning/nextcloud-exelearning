<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Service;

/**
 * Mints and verifies short-lived, fileId-bound capability tokens for the
 * cookieless opaque content-serving route ({@see \OCA\ExeLearning\Controller\ContentController}).
 *
 * The opaque published-content iframe sends no session cookie, so the route
 * cannot rely on the Nextcloud session. Instead {@see ViewController} — where
 * the user IS authenticated and the read permission IS checked — mints a token
 * that binds the file id, the user id and an expiry under an HMAC keyed by the
 * instance secret. The user id lets the serving route mount that user's storage
 * (getUserFolder) and re-check the read permission. The token is the bearer
 * capability: unforgeable, self-describing, stateless (no server-side store).
 * This mirrors Moodle's tokenpluginfile model.
 */
class ContentTokenService {
	/** @var callable():int */
	private $clock;

	/**
	 * @param string $secret The Nextcloud instance secret (wired from IConfig in
	 *                       Application.php); kept as a plain string so this service stays free of
	 *                       OCP dependencies and unit-testable without a server.
	 * @param (callable():int)|null $clock Injectable unix-time source (tests stub it).
	 */
	public function __construct(
		private readonly string $secret,
		?callable $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Mint a capability token binding $fileId + $userId valid for $ttlSeconds.
	 * The user id is carried so the cookieless serving route can mount that
	 * user's storage (getUserFolder) — IRootFolder::getById alone resolves
	 * nothing without a filesystem context on a #[PublicPage].
	 */
	public function mint(int $fileId, string $userId, int $ttlSeconds = 3600): string {
		$expiry = ($this->clock)() + $ttlSeconds;
		$payload = $fileId . '.' . $expiry . '.' . $this->b64($userId);
		return $this->b64($payload) . '.' . $this->b64($this->sign($payload));
	}

	/**
	 * Verify a token: returns `[fileId, userId]` when the signature is valid and
	 * the token has not expired, otherwise null.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	public function verify(string $token): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}
		$payload = $this->unb64($parts[0]);
		$signature = $this->unb64($parts[1]);
		if ($payload === null || $signature === null) {
			return null;
		}
		if (!hash_equals($this->sign($payload), $signature)) {
			return null;
		}
		$segments = explode('.', $payload);
		if (count($segments) !== 3) {
			return null;
		}
		[$fileId, $expiry, $encodedUser] = $segments;
		if (!ctype_digit($fileId) || !ctype_digit($expiry)) {
			return null;
		}
		if ((int)$expiry <= ($this->clock)()) {
			return null;
		}
		$userId = $this->unb64($encodedUser);
		if ($userId === null || $userId === '') {
			return null;
		}
		return [(int)$fileId, $userId];
	}

	private function sign(string $payload): string {
		return hash_hmac('sha256', $payload, $this->secret(), true);
	}

	private function secret(): string {
		return $this->secret . '|exelearning-content-v1';
	}

	private function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private function unb64(string $encoded): ?string {
		$decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
		return $decoded === false ? null : $decoded;
	}
}
