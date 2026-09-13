<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ComposerSecurityPolicyTest extends TestCase
{
    public function test_laravel_baseline_and_security_exceptions_are_focused(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('^10.48.29', $composer['require']['laravel/framework']);

        $advisories = $composer['config']['policy']['advisories'];

        self::assertTrue($advisories['block']);
        self::assertSame('fail', $advisories['audit']);
        self::assertSame([
            'PKSA-m5cs-t1y6-qpcs',
            'PKSA-3r5d-mb8f-1qw9',
            'PKSA-mdq4-51ck-6kdq',
        ], array_keys($advisories['ignore-id']));

        foreach ($advisories['ignore-id'] as $exception) {
            self::assertSame(false, $exception['on-audit']);
            self::assertArrayNotHasKey('on-block', $exception);
            self::assertNotSame('', $exception['reason']);
        }
    }

    public function test_source_does_not_use_affected_local_temporary_url_apis(): void
    {
        $sourceFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'),
        );

        foreach ($sourceFiles as $sourceFile) {
            if (! $sourceFile instanceof SplFileInfo || $sourceFile->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($sourceFile->getPathname());

            self::assertStringNotContainsString('temporaryUrl(', $contents, $sourceFile->getPathname());
            self::assertStringNotContainsString('temporaryUploadUrl(', $contents, $sourceFile->getPathname());
        }
    }
}
