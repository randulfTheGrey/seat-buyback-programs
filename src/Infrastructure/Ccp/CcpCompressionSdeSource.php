<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Ccp;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSdeSource;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\SdeBuild;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;
use Throwable;

final class CcpCompressionSdeSource implements CompressionSdeSource
{
    public function __construct(private readonly Factory $http)
    {
    }

    public function latestBuild(): SdeBuild
    {
        $baseUrl = $this->baseUrl();

        try {
            $response = $this->http
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->connectTimeout())
                ->acceptJson()
                ->get($baseUrl . '/latest.jsonl');

            $response->throw();

            foreach (preg_split('/\R/', trim($response->body())) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                if (($record['_key'] ?? null) !== 'sde') {
                    continue;
                }

                $buildNumber = $record['buildNumber'] ?? null;

                if (! is_int($buildNumber) || $buildNumber <= 0) {
                    break;
                }

                $releasedAt = isset($record['releaseDate']) && is_string($record['releaseDate'])
                    ? new DateTimeImmutable($record['releaseDate'])
                    : null;
                $archiveUrl = sprintf(
                    '%s/eve-online-static-data-%d-jsonl.zip',
                    $baseUrl,
                    $buildNumber,
                );

                return new SdeBuild((string) $buildNumber, $releasedAt, $archiveUrl);
            }
        } catch (CompressionSyncException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CompressionSyncException(
                CompressionSyncFailureCode::BUILD_LOOKUP_FAILURE,
                'Unable to retrieve the current CCP SDE build.',
                $exception,
            );
        }

        throw new CompressionSyncException(
            CompressionSyncFailureCode::BUILD_LOOKUP_FAILURE,
            'The CCP SDE build index did not contain a valid sde build record.',
        );
    }

    public function download(SdeBuild $build, string $destination): void
    {
        try {
            $response = $this->http
                ->connectTimeout($this->connectTimeout())
                ->timeout((int) config('seat-buyback-programs.compression.download_timeout_seconds', 180))
                ->sink($destination)
                ->get($build->archiveUrl);

            $response->throw();
        } catch (Throwable $exception) {
            throw new CompressionSyncException(
                CompressionSyncFailureCode::DOWNLOAD_FAILURE,
                sprintf('Unable to download CCP SDE build %s.', $build->number),
                $exception,
            );
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config(
            'seat-buyback-programs.compression.sde_base_url',
            'https://developers.eveonline.com/static-data/tranquility',
        ), '/');
    }

    private function connectTimeout(): int
    {
        return (int) config('seat-buyback-programs.compression.connect_timeout_seconds', 10);
    }
}
