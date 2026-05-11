<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer configuration for nextcloud-exelearning.
 *
 * Uses the official Nextcloud coding standard so this app stays aligned
 * with what `nextcloud/server` and the rest of the Nextcloud ecosystem
 * expect. Enable locally with:
 *
 *     composer install
 *     vendor/bin/php-cs-fixer fix             # apply fixes
 *     vendor/bin/php-cs-fixer fix --dry-run --diff   # CI mode
 */

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
	fwrite(
		STDERR,
		"vendor/autoload.php is missing — run `composer install` first.\n"
	);
	exit(1);
}
require_once $autoload;

if (!class_exists(\Nextcloud\CodingStandard\Config::class)) {
	fwrite(
		STDERR,
		"nextcloud/coding-standard is not installed. Run:\n"
		. "    composer require --dev nextcloud/coding-standard\n"
	);
	exit(1);
}

$config = new \Nextcloud\CodingStandard\Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('dist')
	->notPath('exelearning')
	->notPath('js')
	->notPath('node_modules')
	->notPath('vendor')
	->in([
		__DIR__ . '/appinfo',
		__DIR__ . '/lib',
		__DIR__ . '/tests',
	]);

$config->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');

return $config;
