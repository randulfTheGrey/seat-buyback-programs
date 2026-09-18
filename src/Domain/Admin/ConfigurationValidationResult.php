<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin;

final readonly class ConfigurationValidationResult
{
    /**
     * @param list<string> $generalErrors
     * @param list<EffectivePolicyDiagnostic> $effectivePolicyDiagnostics
     * @param list<string> $warnings
     */
    public function __construct(
        public array $generalErrors = [],
        public array $effectivePolicyDiagnostics = [],
        public array $warnings = [],
    ) {
        $this->errors = array_values(array_unique(array_merge(
            $generalErrors,
            array_map(
                static fn (EffectivePolicyDiagnostic $diagnostic): string => $diagnostic->managerMessage(),
                $effectivePolicyDiagnostics,
            ),
        )));
    }

    /** @var list<string> */
    public array $errors;

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /**
     * Return errors introduced or made more severe by the proposed configuration.
     *
     * @return list<string>
     */
    public function blockingErrorsComparedTo(?self $before): array
    {
        if ($before === null) {
            return $this->errors;
        }

        $blocking = array_values(array_diff($this->generalErrors, $before->generalErrors));
        $priorDiagnostics = [];

        foreach ($before->effectivePolicyDiagnostics as $diagnostic) {
            $priorDiagnostics[$diagnostic->identity()] = $diagnostic;
        }

        foreach ($this->effectivePolicyDiagnostics as $diagnostic) {
            $prior = $priorDiagnostics[$diagnostic->identity()] ?? null;

            if ($prior === null || $diagnostic->violation->excessBps() > $prior->violation->excessBps()) {
                $blocking[] = $diagnostic->managerMessage();
            }
        }

        return array_values(array_unique($blocking));
    }
}
