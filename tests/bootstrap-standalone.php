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
if (!interface_exists('OCP\\AppFramework\\Bootstrap\\IBootstrap', false)) {
	eval('
		namespace OCP\\AppFramework\\Bootstrap;
		interface IRegistrationContext {
			public function registerPreviewProvider(string $class, string $mimeType): void;
		}
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
		}
	');
}
if (!interface_exists('OCP\\Files\\Folder', false)) {
	eval('
		namespace OCP\\Files;
		interface Folder extends Node {
			public function getById($fileId);
			public function get($path);
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
