<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\InventoryInputError;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;

final readonly class AppraisalLine
{
    /**
     * @param non-empty-list<string> $rawLines
     * @param array<string, mixed>|null $effectivePolicy
     * @param array<string, mixed>|null $pricingProvenance
     */
    public function __construct(
        public int $sourceLineNumber,
        public AppraisalLineStatus $status,
        public array $rawLines,
        public ?string $candidateName = null,
        public ?InventoryInputError $inputError = null,
        public ?int $typeId = null,
        public ?string $typeName = null,
        public ?int $groupId = null,
        public ?int $quantity = null,
        public ?CompressionState $compressionState = null,
        public ?array $effectivePolicy = null,
        public ?ReferenceMode $logicalReferenceMode = null,
        public ?ReferenceResolution $referenceResolution = null,
        public ?array $pricingProvenance = null,
        public ?string $referenceUnitPrice = null,
        public ?int $effectiveModifierBps = null,
        public ?string $finalUnitPrice = null,
        public ?string $lineTotal = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_line_number' => $this->sourceLineNumber,
            'status' => $this->status->value,
            'raw_lines' => $this->rawLines,
            'candidate_name' => $this->candidateName,
            'input_error' => $this->inputError?->value,
            'type_id' => $this->typeId,
            'type_name' => $this->typeName,
            'group_id' => $this->groupId,
            'quantity' => $this->quantity === null ? null : (string) $this->quantity,
            'compression_state' => $this->compressionState?->value,
            'effective_policy' => $this->effectivePolicy,
            'logical_reference_mode' => $this->logicalReferenceMode?->value,
            'reference_resolution' => $this->referenceResolution?->value,
            'pricing_provenance' => $this->pricingProvenance,
            'reference_unit_price' => $this->referenceUnitPrice,
            'effective_modifier_bps' => $this->effectiveModifierBps,
            'final_unit_price' => $this->finalUnitPrice,
            'line_total' => $this->lineTotal,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceLineNumber: (int) $data['source_line_number'],
            status: AppraisalLineStatus::from((string) $data['status']),
            rawLines: array_values((array) $data['raw_lines']),
            candidateName: self::nullableString($data['candidate_name'] ?? null),
            inputError: isset($data['input_error'])
                ? InventoryInputError::from((string) $data['input_error'])
                : null,
            typeId: isset($data['type_id']) ? (int) $data['type_id'] : null,
            typeName: self::nullableString($data['type_name'] ?? null),
            groupId: isset($data['group_id']) ? (int) $data['group_id'] : null,
            quantity: isset($data['quantity']) ? (int) $data['quantity'] : null,
            compressionState: isset($data['compression_state'])
                ? CompressionState::from((string) $data['compression_state'])
                : null,
            effectivePolicy: isset($data['effective_policy']) ? (array) $data['effective_policy'] : null,
            logicalReferenceMode: isset($data['logical_reference_mode'])
                ? ReferenceMode::from((string) $data['logical_reference_mode'])
                : null,
            referenceResolution: isset($data['reference_resolution'])
                ? ReferenceResolution::from((string) $data['reference_resolution'])
                : null,
            pricingProvenance: isset($data['pricing_provenance'])
                ? (array) $data['pricing_provenance']
                : null,
            referenceUnitPrice: self::nullableString($data['reference_unit_price'] ?? null),
            effectiveModifierBps: isset($data['effective_modifier_bps'])
                ? (int) $data['effective_modifier_bps']
                : null,
            finalUnitPrice: self::nullableString($data['final_unit_price'] ?? null),
            lineTotal: self::nullableString($data['line_total'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
