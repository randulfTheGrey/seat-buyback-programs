<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Compression;

use Illuminate\Support\Facades\Http;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;
use RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Ccp\CcpCompressionSdeSource;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class CcpCompressionSdeSourceTest extends TestCase
{
    public function test_build_discovery_parses_the_official_latest_jsonl_record(): void
    {
        Http::fake([
            'https://developers.eveonline.com/static-data/tranquility/latest.jsonl' => Http::response(
                "{\"_key\":\"other\",\"buildNumber\":1}\n"
                . "{\"_key\":\"sde\",\"buildNumber\":3503375,\"releaseDate\":\"2026-09-10T11:09:04Z\"}\n",
            ),
        ]);

        $build = $this->app->make(CcpCompressionSdeSource::class)->latestBuild();

        self::assertSame('3503375', $build->number);
        self::assertSame('2026-09-10T11:09:04+00:00', $build->releasedAt?->format(DATE_ATOM));
        self::assertSame(
            'https://developers.eveonline.com/static-data/tranquility/eve-online-static-data-3503375-jsonl.zip',
            $build->archiveUrl,
        );
        Http::assertSentCount(1);
    }

    public function test_invalid_build_index_is_normalized(): void
    {
        Http::fake(['*' => Http::response("{\"_key\":\"sde\",\"buildNumber\":0}\n")]);

        try {
            $this->app->make(CcpCompressionSdeSource::class)->latestBuild();
            self::fail('Expected build lookup to fail.');
        } catch (CompressionSyncException $exception) {
            self::assertSame(CompressionSyncFailureCode::BUILD_LOOKUP_FAILURE, $exception->failureCode);
            self::assertStringContainsString('valid sde build record', $exception->getMessage());
        }
    }

    public function test_http_failure_is_normalized_without_response_details(): void
    {
        Http::fake(['*' => Http::response('private upstream failure', 500)]);

        try {
            $this->app->make(CcpCompressionSdeSource::class)->latestBuild();
            self::fail('Expected build lookup to fail.');
        } catch (CompressionSyncException $exception) {
            self::assertSame(CompressionSyncFailureCode::BUILD_LOOKUP_FAILURE, $exception->failureCode);
            self::assertSame('Unable to retrieve the current CCP SDE build.', $exception->getMessage());
            self::assertStringNotContainsString('private upstream failure', $exception->getMessage());
        }
    }
}
