<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin;

final readonly class ProgramHealth
{
    /**
     * @param array<string, array{healthy: bool, message: string}> $references
     * @param list<string> $runtimeErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public ConfigurationValidationResult $configuration,
        public array $references,
        public array $runtimeErrors = [],
        public array $warnings = [],
    ) {
    }

    public function operational(): bool
    {
        return $this->configuration->valid() && $this->runtimeErrors === [];
    }

    public function label(): string
    {
        if (! $this->configuration->valid()) {
            return 'Invalid configuration';
        }

        return $this->runtimeErrors === [] ? 'Healthy' : 'Degraded';
    }
}
