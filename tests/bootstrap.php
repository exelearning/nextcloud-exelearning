<?php

declare(strict_types=1);

/**
 * Lightweight bootstrap so the unit tests in this directory can run without a
 * full Nextcloud server. Tests that need server-side APIs should stub them
 * with PHPUnit's MockBuilder.
 *
 * `bootstrap-standalone.php` declares the minimal OCP\* class / interface
 * stubs that classes under lib/ inherit from. Pulling it in here means
 * importing e.g. `OCA\ExeLearning\AppInfo\Application` from a unit test
 * does not blow up at class-resolution time when no Nextcloud server is
 * available (CI runners, local `composer test`).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap-standalone.php';
