<?php

declare(strict_types=1);

/**
 * Lightweight bootstrap for running the pure-PHP unit tests without composer
 * autoload. Maps the OCA\ExeLearning\* namespaces to lib/ via PSR-4 by hand
 * and stubs the few OCP\* classes that the loaded classes touch at
 * `class … extends …` resolution time. Stubs are intentionally empty — the
 * tests under tests/Unit/ never exercise behaviour from these base classes.
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\ExeLearning\\';
	if (str_starts_with($class, $prefix)) {
		$relative = substr($class, strlen($prefix));
		$relative = str_replace('\\', '/', $relative);
		$file = __DIR__ . '/../lib/' . $relative . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});

if (!class_exists('OCP\\AppFramework\\App', false)) {
	eval('
		namespace OCP\\AppFramework;
		class App {
			public function __construct(string $appName, array $urlParams = []) {}
		}
	');
}
if (!class_exists('OCP\\AppFramework\\Controller', false)) {
	eval('
		namespace OCP\AppFramework;
		class Controller {
			public function __construct(string $appName, protected object $request) {}
		}
		class Http {
			public const STATUS_OK = 200;
			public const STATUS_NO_CONTENT = 204;
			public const STATUS_BAD_REQUEST = 400;
			public const STATUS_UNAUTHORIZED = 401;
			public const STATUS_FORBIDDEN = 403;
			public const STATUS_NOT_FOUND = 404;
		}
	');
}
if (!class_exists('OCP\\AppFramework\\Http\\DataDisplayResponse', false)) {
	eval('
		namespace OCP\AppFramework\Http;
		class DataDisplayResponse {
			private array $headers;
			public function __construct(private string $data = "", private int $status = 200, array $headers = []) {
				$this->headers = $headers;
			}
			public function addHeader(string $name, string $value): void { $this->headers[$name] = $value; }
			public function getHeaders(): array { return $this->headers; }
			public function getData(): string { return $this->data; }
			public function getStatus(): int { return $this->status; }
		}
		class DataResponse extends DataDisplayResponse {
			public function __construct(array $data = [], int $status = 200, array $headers = []) {
				parent::__construct(json_encode($data), $status, $headers);
			}
		}
	');
}
if (!interface_exists('OCP\\IRequest', false)) {
	eval('namespace OCP; interface IRequest {} interface IUserSession {} interface IConfig {}');
}
if (!class_exists('OCP\\Files\\NotFoundException', false)) {
	eval('namespace OCP\Files; class NotFoundException extends \\Exception {} class NotPermittedException extends \\Exception {}');
}
if (!interface_exists('OCP\\AppFramework\\Bootstrap\\IBootstrap', false)) {
	eval('
		namespace OCP\\AppFramework\\Bootstrap;
		interface IRegistrationContext {}
		interface IBootContext { public function getServerContainer(); public function getAppContainer(); }
		interface IBootstrap {
			public function register(IRegistrationContext $context): void;
			public function boot(IBootContext $context): void;
		}
	');
}
if (!interface_exists('OCP\\IPreview', false)) {
	eval('namespace OCP; interface IPreview {}');
}
if (!class_exists('OCP\\Util', false)) {
	eval('namespace OCP; class Util { public static function addInitScript(string $app, string $script): void {} public static function addScript(string $app, string $script): void {} }');
}
