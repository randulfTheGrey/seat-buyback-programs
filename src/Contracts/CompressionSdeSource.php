<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Contracts;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\SdeBuild;

interface CompressionSdeSource
{
    public function latestBuild(): SdeBuild;

    public function download(SdeBuild $build, string $destination): void;
}
