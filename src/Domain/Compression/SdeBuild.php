<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression;

use DateTimeImmutable;

final readonly class SdeBuild
{
    public function __construct(
        public string $number,
        public ?DateTimeImmutable $releasedAt,
        public string $archiveUrl,
    ) {
    }

    /**
     * @return array{build_number: string, release_date: ?string, archive_url: string}
     */
    public function metadata(): array
    {
        return [
            'build_number' => $this->number,
            'release_date' => $this->releasedAt?->format(DATE_ATOM),
            'archive_url' => $this->archiveUrl,
        ];
    }
}
