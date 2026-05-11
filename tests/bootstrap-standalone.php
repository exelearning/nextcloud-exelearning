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
