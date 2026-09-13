<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

use Carbon\CarbonImmutable;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;

final readonly class AppraisalResult
{
    /**
     * @param list<AppraisalLine> $lines
     * @param list<string> $warnings
     */
    public function __construct(
        public int $programId,
        public string $programName,
        public int $requesterUserId,
        public string $originalInput,
        public CarbonImmutable $pricedAt,
        public CarbonImmutable $quoteExpiresAt,
        public array $lines,
        public string $quoteableTotal,
        public array $warnings = [],
    ) {
    }

    /** @return array<string, int> */
    public function summaryCounts(): array
    {
        $counts = array_fill_keys(
            array_map(static fn (AppraisalLineStatus $status): string => $status->value, AppraisalLineStatus::cases()),
            0,
        );

        foreach ($this->lines as $line) {
            $counts[$line->status->value]++;
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'program' => [
                'id' => $this->programId,
                'name' => $this->programName,
            ],
            'requester_user_id' => $this->requesterUserId,
            'original_input' => $this->originalInput,
            'priced_at' => $this->pricedAt->toIso8601String(),
            'quote_expires_at' => $this->quoteExpiresAt->toIso8601String(),
            'lines' => array_map(
                static fn (AppraisalLine $line): array => $line->toArray(),
                $this->lines,
            ),
            'quoteable_total' => $this->quoteableTotal,
            'summary_counts' => $this->summaryCounts(),
            'warnings' => $this->warnings,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== 1) {
            throw new \UnexpectedValueException('Unsupported cached appraisal schema version.');
        }

        $program = (array) $data['program'];

        return new self(
            programId: (int) $program['id'],
            programName: (string) $program['name'],
            requesterUserId: (int) $data['requester_user_id'],
            originalInput: (string) $data['original_input'],
            pricedAt: CarbonImmutable::parse((string) $data['priced_at']),
            quoteExpiresAt: CarbonImmutable::parse((string) $data['quote_expires_at']),
            lines: array_map(
                static fn (array $line): AppraisalLine => AppraisalLine::fromArray($line),
                (array) $data['lines'],
            ),
            quoteableTotal: (string) $data['quoteable_total'],
            warnings: array_values((array) ($data['warnings'] ?? [])),
        );
    }
}
