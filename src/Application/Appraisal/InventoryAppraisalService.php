<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionClassifier;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Pricing\ProgramReferenceConfigurationLoader;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Pricing\ReferencePriceCoordinator;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\InventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalLine;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\CompletedAppraisal;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\ResolvedInventoryType;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ReferencePriceRequirement;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\EffectivePolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyEvaluator;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyRule;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionDataStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferencePriceStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalInfrastructureException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalInputException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalLimitExceededException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalPolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalPricingConfigurationException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionReferenceDataUnavailableException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\DuplicatePolicyRuleException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidEffectivePolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidPolicyRuleException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\ProgramUnavailableForAppraisalException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\Persistence\PolicyInputMapper;
use Throwable;
use ValueError;

final readonly class InventoryAppraisalService
{
    public function __construct(
        private InventoryParser $parser,
        private InventoryTypeResolver $typeResolver,
        private CompressionClassifier $compressionClassifier,
        private CompressionHealthService $compressionHealth,
        private PolicyInputMapper $policyInputMapper,
        private PolicyEvaluator $policyEvaluator,
        private BuybackPriceCalculator $priceCalculator,
        private ProgramReferenceConfigurationLoader $referenceConfigurationLoader,
        private ReferencePriceCoordinator $priceCoordinator,
        private AppraisalStore $store,
    ) {
    }

    public function appraise(
        BuybackProgram $program,
        int $requesterUserId,
        string $originalInput,
    ): CompletedAppraisal {
        if ($requesterUserId <= 0) {
            throw new AppraisalPolicyException('Appraisal requires an authenticated requester.');
        }

        $this->assertProgramEligible($program);
        $this->assertInputSize($originalInput);

        $parsed = $this->parser->parse($originalInput);
        $maxLines = $this->positiveConfig('max_input_lines', 2000);

        if ($parsed->nonBlankLineCount > $maxLines) {
            throw new AppraisalLimitExceededException(sprintf(
                'An appraisal may contain at most %d nonblank input lines.',
                $maxLines,
            ));
        }

        $resolvedByName = $this->typeResolver->resolveExact(array_map(
            static fn ($line): string => $line->candidateName,
            $parsed->parsedLines,
        ));

        $resultLines = [];

        foreach ($parsed->invalidLines as $invalid) {
            $resultLines[] = new AppraisalLine(
                sourceLineNumber: $invalid->lineNumber,
                status: AppraisalLineStatus::INVALID_INPUT,
                rawLines: [$invalid->rawLine],
                candidateName: $invalid->candidateName,
                inputError: $invalid->error,
            );
        }

        /** @var array<int, array{type: ResolvedInventoryType, quantity: int, first_line: int, raw_lines: list<string>}> $merged */
        $merged = [];

        foreach ($parsed->parsedLines as $line) {
            $type = $resolvedByName[$line->candidateName] ?? null;

            if ($type === null) {
                $resultLines[] = new AppraisalLine(
                    sourceLineNumber: $line->lineNumber,
                    status: AppraisalLineStatus::UNKNOWN,
                    rawLines: [$line->rawLine],
                    candidateName: $line->candidateName,
                    quantity: $line->quantity,
                );
                continue;
            }

            if (! isset($merged[$type->typeId])) {
                $merged[$type->typeId] = [
                    'type' => $type,
                    'quantity' => $line->quantity,
                    'first_line' => $line->lineNumber,
                    'raw_lines' => [$line->rawLine],
                ];
                continue;
            }

            if ($merged[$type->typeId]['quantity'] > PHP_INT_MAX - $line->quantity) {
                throw new AppraisalInputException(sprintf(
                    'Merged quantity for type ID %d exceeds the supported integer range.',
                    $type->typeId,
                ));
            }

            $merged[$type->typeId]['quantity'] += $line->quantity;
            $merged[$type->typeId]['raw_lines'][] = $line->rawLine;
        }

        $maxUniqueTypes = $this->positiveConfig('max_unique_types', 500);

        if (count($merged) > $maxUniqueTypes) {
            throw new AppraisalLimitExceededException(sprintf(
                'An appraisal may contain at most %d unique resolved types.',
                $maxUniqueTypes,
            ));
        }

        [$classifications, $warnings] = $this->classify($program, array_keys($merged));
        [$defaults, $rulesByTarget] = $this->loadPolicyInputs($program, $merged);
        $accepted = [];

        foreach ($merged as $typeId => $item) {
            $type = $item['type'];

            try {
                $policy = $this->policyEvaluator->evaluate(
                    $defaults,
                    new ItemPolicyContext($typeId, $type->groupId, $classifications[$typeId]),
                    $this->rulesForItem($rulesByTarget, $typeId, $type->groupId),
                );
            } catch (InvalidEffectivePolicyException|DuplicatePolicyRuleException|InvalidArgumentException $exception) {
                throw new AppraisalPolicyException(
                    'The Program produced an invalid effective policy.',
                    previous: $exception,
                );
            }

            if ($policy->acceptance === Acceptance::REJECT) {
                $resultLines[] = $this->policyLine(
                    $item,
                    $classifications[$typeId],
                    $policy,
                    AppraisalLineStatus::EXCLUDED,
                );
                continue;
            }

            $accepted[$typeId] = [
                'item' => $item,
                'compression' => $classifications[$typeId],
                'policy' => $policy,
            ];
        }

        if ($accepted !== []) {
            $requirements = array_map(
                static fn (array $entry): ReferencePriceRequirement => new ReferencePriceRequirement(
                    $entry['item']['type']->typeId,
                    $entry['policy']->referenceMode,
                ),
                array_values($accepted),
            );

            try {
                $pricing = $this->priceCoordinator->price(
                    $requirements,
                    $this->referenceConfigurationLoader->forProgram($program),
                );
            } catch (Throwable $exception) {
                throw new AppraisalInfrastructureException(
                    'Reference pricing failed unexpectedly.',
                    previous: $exception,
                );
            }

            foreach ($accepted as $typeId => $entry) {
                $outcome = $pricing->outcome($typeId, $entry['policy']->referenceMode);

                if ($outcome?->status === ReferencePriceStatus::REFERENCE_MISCONFIGURED) {
                    throw new AppraisalPricingConfigurationException(
                        'A required logical price reference is misconfigured.',
                    );
                }

                if ($outcome === null) {
                    throw new AppraisalInfrastructureException(
                        'Reference pricing returned an incomplete result.',
                    );
                }

                $resultLines[] = $this->pricedOrUnavailableLine($entry, $outcome);
            }
        }

        usort(
            $resultLines,
            static fn (AppraisalLine $left, AppraisalLine $right): int =>
                $left->sourceLineNumber <=> $right->sourceLineNumber,
        );

        $quoteableTotal = BigDecimal::of('0.00');

        foreach ($resultLines as $line) {
            if ($line->status === AppraisalLineStatus::PRICED) {
                $quoteableTotal = $quoteableTotal->plus($line->lineTotal);
            }
        }

        $pricedAt = CarbonImmutable::now();
        $validityMinutes = (int) $program->quote_validity_minutes;

        if ($validityMinutes <= 0) {
            throw new AppraisalPolicyException('The Program quote validity must be positive.');
        }

        $result = new AppraisalResult(
            programId: (int) $program->getKey(),
            programName: (string) $program->name,
            requesterUserId: $requesterUserId,
            originalInput: $originalInput,
            pricedAt: $pricedAt,
            quoteExpiresAt: $pricedAt->addMinutes($validityMinutes),
            lines: $resultLines,
            quoteableTotal: (string) $quoteableTotal->toScale(2),
            warnings: $warnings,
        );

        return new CompletedAppraisal($this->store->put($result), $result);
    }

    private function assertProgramEligible(BuybackProgram $program): void
    {
        if ((int) $program->getKey() <= 0) {
            throw new AppraisalPolicyException('Appraisal requires a persisted Buyback Program.');
        }

        try {
            $status = $program->status;
        } catch (ValueError $exception) {
            throw new AppraisalPolicyException('The Program has an invalid lifecycle status.', previous: $exception);
        }

        if ($status !== ProgramStatus::ENABLED) {
            throw new ProgramUnavailableForAppraisalException(
                'New appraisals require an enabled Buyback Program.',
            );
        }
    }

    private function assertInputSize(string $input): void
    {
        $maxBytes = $this->positiveConfig('max_input_bytes', 262144);

        if (strlen($input) > $maxBytes) {
            throw new AppraisalLimitExceededException(sprintf(
                'Appraisal input may contain at most %d bytes.',
                $maxBytes,
            ));
        }
    }

    /**
     * @param list<int> $typeIds
     * @return array{array<int, CompressionState>, list<string>}
     */
    private function classify(BuybackProgram $program, array $typeIds): array
    {
        if ($typeIds === []) {
            return [[], []];
        }

        $health = $this->compressionHealth->current();

        if (! $health->usable) {
            $requiresCompression = $program->rules()
                ->where('enabled', true)
                ->whereNull('archived_at')
                ->where('target_type', RuleTargetType::GROUP->value)
                ->whereIn('compression_qualifier', [
                    CompressionQualifier::COMPRESSED->value,
                    CompressionQualifier::UNCOMPRESSED->value,
                ])
                ->exists();

            if ($requiresCompression) {
                throw new CompressionReferenceDataUnavailableException(
                    'Compression reference data is required by this Program but is unavailable.',
                );
            }

            return [array_fill_keys($typeIds, CompressionState::NOT_APPLICABLE), []];
        }

        return [
            $this->compressionClassifier->classifyBatch($typeIds),
            $health->status === CompressionDataStatus::STALE ? ['COMPRESSION_DATA_STALE'] : [],
        ];
    }

    /**
     * @param array<int, array{type: ResolvedInventoryType, quantity: int, first_line: int, raw_lines: list<string>}> $merged
     * @return array{\RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ProgramPolicyDefaults, array<string, list<PolicyRule>>}
     */
    private function loadPolicyInputs(BuybackProgram $program, array $merged): array
    {
        try {
            $defaults = $this->policyInputMapper->defaults($program);

            if ($merged === []) {
                return [$defaults, []];
            }

            $typeIds = array_keys($merged);
            $groupIds = array_values(array_unique(array_map(
                static fn (array $item): int => $item['type']->groupId,
                $merged,
            )));
            $models = BuybackRule::query()
                ->where('program_id', $program->getKey())
                ->where('enabled', true)
                ->whereNull('archived_at')
                ->where(static function ($query) use ($typeIds, $groupIds): void {
                    $query->where(static function ($query) use ($typeIds): void {
                        $query->where('target_type', RuleTargetType::TYPE->value)
                            ->whereIn('target_id', $typeIds);
                    })->orWhere(static function ($query) use ($groupIds): void {
                        $query->where('target_type', RuleTargetType::GROUP->value)
                            ->whereIn('target_id', $groupIds);
                    });
                })
                ->get();
            $rules = $this->policyInputMapper->activeRules($models);
        } catch (InvalidPolicyRuleException|InvalidArgumentException|ValueError $exception) {
            throw new AppraisalPolicyException('The Program contains invalid policy configuration.', previous: $exception);
        }

        $indexed = [];

        foreach ($rules as $rule) {
            $indexed[$rule->targetType->value . ':' . $rule->targetId][] = $rule;
        }

        return [$defaults, $indexed];
    }

    /**
     * @param array<string, list<PolicyRule>> $rulesByTarget
     * @return list<PolicyRule>
     */
    private function rulesForItem(array $rulesByTarget, int $typeId, int $groupId): array
    {
        return array_merge(
            $rulesByTarget[RuleTargetType::GROUP->value . ':' . $groupId] ?? [],
            $rulesByTarget[RuleTargetType::TYPE->value . ':' . $typeId] ?? [],
        );
    }

    /**
     * @param array{type: ResolvedInventoryType, quantity: int, first_line: int, raw_lines: list<string>} $item
     */
    private function policyLine(
        array $item,
        CompressionState $compression,
        EffectivePolicy $policy,
        AppraisalLineStatus $status,
    ): AppraisalLine {
        return new AppraisalLine(
            sourceLineNumber: $item['first_line'],
            status: $status,
            rawLines: $item['raw_lines'],
            candidateName: $item['type']->typeName,
            typeId: $item['type']->typeId,
            typeName: $item['type']->typeName,
            groupId: $item['type']->groupId,
            quantity: $item['quantity'],
            compressionState: $compression,
            effectivePolicy: [
                'schema_version' => 1,
                ...$policy->toArray(),
            ],
            logicalReferenceMode: $policy->referenceMode,
            effectiveModifierBps: $policy->effectiveModifierBps->value,
        );
    }

    /**
     * @param array{item: array{type: ResolvedInventoryType, quantity: int, first_line: int, raw_lines: list<string>}, compression: CompressionState, policy: EffectivePolicy} $entry
     */
    private function pricedOrUnavailableLine(
        array $entry,
        ReferencePriceOutcome $outcome,
    ): AppraisalLine {
        $status = match ($outcome->status) {
            ReferencePriceStatus::UNPRICED => AppraisalLineStatus::UNPRICED,
            ReferencePriceStatus::REFERENCE_UNAVAILABLE => AppraisalLineStatus::REFERENCE_UNAVAILABLE,
            ReferencePriceStatus::PRICED => AppraisalLineStatus::PRICED,
            ReferencePriceStatus::REFERENCE_MISCONFIGURED => throw new AppraisalPricingConfigurationException(
                'A required logical price reference is misconfigured.',
            ),
        };

        if ($status !== AppraisalLineStatus::PRICED) {
            return $this->policyLine(
                $entry['item'],
                $entry['compression'],
                $entry['policy'],
                $status,
            );
        }

        if ($outcome->referencePrice === null || $outcome->provenance === null) {
            throw new AppraisalInfrastructureException('A priced reference outcome has incomplete evidence.');
        }

        $calculated = $this->priceCalculator->calculate(
            $outcome->referencePrice,
            $entry['policy']->effectiveModifierBps,
            $entry['item']['quantity'],
        );

        $base = $this->policyLine(
            $entry['item'],
            $entry['compression'],
            $entry['policy'],
            AppraisalLineStatus::PRICED,
        );

        return new AppraisalLine(
            sourceLineNumber: $base->sourceLineNumber,
            status: $base->status,
            rawLines: $base->rawLines,
            candidateName: $base->candidateName,
            typeId: $base->typeId,
            typeName: $base->typeName,
            groupId: $base->groupId,
            quantity: $base->quantity,
            compressionState: $base->compressionState,
            effectivePolicy: $base->effectivePolicy,
            logicalReferenceMode: $base->logicalReferenceMode,
            referenceResolution: $outcome->provenance->resolution,
            pricingProvenance: $this->pricingProvenance($outcome),
            referenceUnitPrice: (string) $outcome->referencePrice,
            effectiveModifierBps: $base->effectiveModifierBps,
            finalUnitPrice: (string) $calculated->finalUnitPrice,
            lineTotal: (string) $calculated->lineTotal,
        );
    }

    /** @return array<string, mixed> */
    private function pricingProvenance(ReferencePriceOutcome $outcome): array
    {
        return [
            'schema_version' => 1,
            'logical_mode' => $outcome->logicalMode->value,
            'resolution' => $outcome->provenance?->resolution->value,
            'components' => array_map(
                static fn ($component): array => [
                    'mode' => $component->mode->value,
                    'provider_instance_id' => $component->providerInstanceId,
                    'provider_instance_name' => $component->providerInstanceName,
                    'reference_price' => (string) $component->referencePrice,
                ],
                $outcome->provenance?->components ?? [],
            ),
        ];
    }

    private function positiveConfig(string $key, int $default): int
    {
        $value = (int) config('seat-buyback-programs.appraisal.' . $key, $default);

        return $value > 0 ? $value : $default;
    }
}
