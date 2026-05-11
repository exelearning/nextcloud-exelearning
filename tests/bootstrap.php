<?php

declare(strict_types=1);

/**
 * Lightweight bootstrap so the unit tests in this directory can run without a
 * full Nextcloud server. Tests that need server-side APIs should stub them
 * with PHPUnit's MockBuilder.
 */

require_once __DIR__ . '/../vendor/autoload.php';
