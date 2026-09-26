<?php

declare(strict_types=1);

namespace OCA\ExeLearning\Tests\Unit\Service;

use OCA\ExeLearning\Service\EmbedShimInjector;
use PHPUnit\Framework\TestCase;

final class EmbedShimInjectorTest extends TestCase {
	private EmbedShimInjector $injector;

	protected function setUp(): void {
		$this->injector = new EmbedShimInjector();
	}

	public function testInjectsBeforeHeadClose(): void {
		$html = '<html><head><title>x</title></head><body>hi</body></html>';
		$out = $this->injector->injectIntoHead($html, 'CONSOLE.LOG(1)');
		self::assertStringContainsString('data-injected-by="eXeLearning-Viewer"', $out);
		self::assertStringContainsString('CONSOLE.LOG(1)', $out);
		// Script sits immediately before </head>, i.e. before <body>.
		self::assertLessThan(strpos($out, '<body'), strpos($out, 'CONSOLE.LOG(1)'));
	}

	public function testCaseInsensitiveHeadClose(): void {
		$out = $this->injector->injectIntoHead('<HTML><HEAD></HEAD><BODY></BODY></HTML>', 'X');
		self::assertStringContainsString('<script data-injected-by="eXeLearning-Viewer">X</script></HEAD>', $out);
	}

	public function testPreservesRegexSpecialCharsInSource(): void {
		$source = 'var a = "$1"; var b = "\\\\n"; // $0 \\1';
		$out = $this->injector->injectIntoHead('<head></head>', $source);
		self::assertStringContainsString($source, $out);
	}

	public function testFallsBackToAfterBodyWhenNoHead(): void {
		$out = $this->injector->injectIntoHead('<body class="p">hi</body>', 'Y');
		self::assertStringContainsString('<body class="p"><script data-injected-by="eXeLearning-Viewer">Y</script>', $out);
	}

	public function testPrependsWhenNoHeadNoBody(): void {
		$out = $this->injector->injectIntoHead('<p>bare</p>', 'Z');
		self::assertStringStartsWith('<script data-injected-by="eXeLearning-Viewer">Z</script>', $out);
	}
}
