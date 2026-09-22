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

if (!interface_exists('Psr\\Container\\ContainerInterface', false)) {
	eval('
		namespace Psr\\Container;
		interface ContainerInterface {
			public function get(string $id);
			public function has(string $id): bool;
		}
	');
}
if (!class_exists('OCP\\AppFramework\\App', false)) {
	eval('
		namespace OCP\\AppFramework;
		class App {
			public function __construct(string $appName, array $urlParams = []) {}
		}
	');
}
if (!interface_exists('OCP\\AppFramework\\Bootstrap\\IBootstrap', false)) {
	eval('
		namespace OCP\\AppFramework\\Bootstrap;
		interface IRegistrationContext {
			public function registerPreviewProvider(string $class, string $mimeType): void;
			public function registerService(string $name, callable $factory): void;
		}
		interface IBootContext { public function getServerContainer(); public function getAppContainer(); }
		interface IBootstrap {
			public function register(IRegistrationContext $context): void;
			public function boot(IBootContext $context): void;
		}
	');
}
if (!class_exists('OC\\Security\\CSRF\\CsrfTokenManager', false)) {
	eval('
		namespace OC\\Security\\CSRF;
		class CsrfToken {
			public function getEncryptedValue(): string { return "test-request-token"; }
		}
		class CsrfTokenManager {
			public function getToken(): CsrfToken { return new CsrfToken(); }
		}
	');
}
if (!interface_exists('OCP\\IConfig', false)) {
	eval('
		namespace OCP;
		interface IConfig {
			public function getSystemValue(string $key, mixed $default = null);
			public function getAppValue(string $app, string $key, string $default = "");
		}
	');
}
if (!interface_exists('OCP\\IRequest', false)) {
	eval('
		namespace OCP;
		interface IRequest {
			public function getUploadedFile(string $key);
			public function getHeader(string $key);
		}
	');
}
if (!interface_exists('OCP\\IUser', false)) {
	eval('namespace OCP; interface IUser { public function getUID(); }');
}
if (!interface_exists('OCP\\IUserSession', false)) {
	eval('namespace OCP; interface IUserSession { public function getUser(); }');
}
if (!interface_exists('OCP\\IURLGenerator', false)) {
	eval('
		namespace OCP;
		interface IURLGenerator {
			public function linkTo(string $app, string $file);
			public function linkToRoute(string $routeName, array $arguments = []);
		}
	');
}
if (!interface_exists('OCP\\AppFramework\\Services\\IInitialState', false)) {
	eval('
		namespace OCP\\AppFramework\\Services;
		interface IInitialState {
			public function provideInitialState(string $key, mixed $value): void;
		}
	');
}
if (!class_exists('OCP\\AppFramework\\Controller', false)) {
	eval('
		namespace OCP\\AppFramework;
		class Controller {
			protected \\OCP\\IRequest $request;
			public function __construct(string $appName, \\OCP\\IRequest $request) {
				$this->request = $request;
			}
		}
	');
}
if (!class_exists('OCP\\AppFramework\\Http', false)) {
	eval('
		namespace OCP\\AppFramework;
		class Http {
			public const STATUS_OK = 200;
			public const STATUS_BAD_REQUEST = 400;
			public const STATUS_UNAUTHORIZED = 401;
			public const STATUS_FORBIDDEN = 403;
			public const STATUS_NOT_FOUND = 404;
			public const STATUS_PRECONDITION_FAILED = 412;
			public const STATUS_INTERNAL_SERVER_ERROR = 500;
		}
	');
}
if (!class_exists('OCP\\AppFramework\\Http\\ContentSecurityPolicy', false)) {
	eval('
		namespace OCP\\AppFramework\\Http;
		class ContentSecurityPolicy {
			public array $workerSrc = [];
			public array $scriptDomains = [];
			public array $connectDomains = [];
			public array $frameDomains = [];
			public function addAllowedWorkerSrcDomain(string $domain): void { $this->workerSrc[] = $domain; }
			public function addAllowedScriptDomain(string $domain): void { $this->scriptDomains[] = $domain; }
			public function addAllowedConnectDomain(string $domain): void { $this->connectDomains[] = $domain; }
			public function addAllowedFrameDomain(string $domain): void { $this->frameDomains[] = $domain; }
		}
	');
}
if (!class_exists('OCP\\AppFramework\\Http\\DataResponse', false)) {
	eval('
		namespace OCP\\AppFramework\\Http;
		class DataResponse {
			protected array $headers;
			public function __construct(
				protected mixed $data = null,
				protected int $status = 200,
				array $headers = [],
			) { $this->headers = $headers; }
			public function getData(): mixed { return $this->data; }
			public function getStatus(): int { return $this->status; }
			public function addHeader(string $name, string $value): void { $this->headers[$name] = $value; }
			public function getHeaders(): array { return $this->headers; }
		}
		class DataDisplayResponse extends DataResponse {}
		class TemplateResponse extends DataResponse {
			public const RENDER_AS_USER = "user";
			public ?ContentSecurityPolicy $contentSecurityPolicy = null;
			public function __construct(
				public string $appName,
				public string $templateName,
				array $params = [],
				public string $renderAs = self::RENDER_AS_USER,
			) { parent::__construct($params, 200); }
			public function setContentSecurityPolicy(ContentSecurityPolicy $policy): void { $this->contentSecurityPolicy = $policy; }
			public function getContentSecurityPolicy(): ?ContentSecurityPolicy { return $this->contentSecurityPolicy; }
		}
		class StreamResponse extends DataResponse {
			public function __construct($stream) { parent::__construct($stream, 200); }
			public function getStream() { return $this->data; }
		}
	');
}
if (!interface_exists('OCP\\IPreview', false)) {
	eval('namespace OCP; interface IPreview {}');
}
if (!interface_exists('OCP\\Files\\FileInfo', false)) {
	eval('
		namespace OCP\\Files;
		interface FileInfo {
			public function getName();
		}
	');
}
if (!interface_exists('OCP\\Files\\Node', false)) {
	eval('
		namespace OCP\\Files;
		interface Node extends FileInfo {
			public function getMimeType();
			public function getPermissions();
		}
	');
}
if (!interface_exists('OCP\\Files\\File', false)) {
	// Like the real OCP\Files\File (an interface extending Node), but reduced
	// to the members our code touches. Methods stay untyped as in the real
	// API so PHPUnit mocks can implement them freely.
	eval('
		namespace OCP\\Files;
		interface File extends Node {
			public function getStorage();
			public function getInternalPath();
			public function fopen($mode);
			public function getSize();
			public function getId();
			public function getPath();
			public function getMTime();
			public function getEtag();
			public function isUpdateable();
			public function getParent();
			public function move($target);
			public function putContent($data);
		}
	');
}
if (!interface_exists('OCP\\Files\\Folder', false)) {
	eval('
		namespace OCP\\Files;
		interface Folder extends Node {
			public function getById($fileId);
			public function get($path);
			public function nodeExists($path);
			public function getPath();
		}
	');
}
if (!interface_exists('OCP\\Files\\IRootFolder', false)) {
	eval('
		namespace OCP\\Files;
		interface IRootFolder {
			public function getUserFolder($userId);
		}
	');
}
if (!class_exists('OCP\\Files\\NotFoundException', false)) {
	eval('namespace OCP\\Files; class NotFoundException extends \\Exception {}');
}
if (!class_exists('OCP\\Files\\NotPermittedException', false)) {
	eval('namespace OCP\\Files; class NotPermittedException extends \\Exception {}');
}
if (!class_exists('OCP\\Files\\InvalidPathException', false)) {
	eval('namespace OCP\\Files; class InvalidPathException extends \\Exception {}');
}
if (!class_exists('OCP\\Constants', false)) {
	eval('namespace OCP; class Constants { public const PERMISSION_READ = 1; }');
}
if (!interface_exists('OCP\\IImage', false)) {
	eval('namespace OCP; interface IImage {}');
}
if (!class_exists('OCP\\Image', false)) {
	eval('
		namespace OCP;
		class Image implements IImage {
			public static ?self $lastInstance = null;
			public static ?bool $validOverride = null;
			public string $data = "";
			public ?array $scaledTo = null;
			public function __construct() { self::$lastInstance = $this; }
			public function loadFromData(string $data): void { $this->data = $data; }
			public function valid(): bool { return self::$validOverride ?? ($this->data !== "" && $this->data !== "invalid"); }
			public function scaleDownToFit(int $maxX, int $maxY): void { $this->scaledTo = [$maxX, $maxY]; }
		}
	');
}
if (!interface_exists('OCP\\Preview\\IProviderV2', false)) {
	eval('namespace OCP\\Preview; interface IProviderV2 {}');
}
if (!interface_exists('OCP\\Files\\SimpleFS\\ISimpleFile', false)) {
	eval('namespace OCP\\Files\\SimpleFS; interface ISimpleFile {}');
}
if (!interface_exists('Psr\\Log\\LoggerInterface', false)) {
	eval('
		namespace Psr\\Log;
		interface LoggerInterface {
			public function emergency(string|\\Stringable $message, array $context = []): void;
			public function alert(string|\\Stringable $message, array $context = []): void;
			public function critical(string|\\Stringable $message, array $context = []): void;
			public function error(string|\\Stringable $message, array $context = []): void;
			public function warning(string|\\Stringable $message, array $context = []): void;
			public function notice(string|\\Stringable $message, array $context = []): void;
			public function info(string|\\Stringable $message, array $context = []): void;
			public function debug(string|\\Stringable $message, array $context = []): void;
			public function log($level, string|\\Stringable $message, array $context = []): void;
		}
	');
}
if (!class_exists('OCP\\Util', false)) {
	eval('
		namespace OCP;
		class Util {
			public static array $initScripts = [];
			public static array $scripts = [];
			public static function addInitScript(string $app, string $script): void { self::$initScripts[] = [$app, $script]; }
			public static function addScript(string $app, string $script): void { self::$scripts[] = [$app, $script]; }
			public static function reset(): void { self::$initScripts = []; self::$scripts = []; }
		}
	');
}
