<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final readonly class SanctionFormData
{
    /**
     * @param list<string> $reportIds
     * @param list<string> $measureIds
     */
    public function __construct(
        public array $reportIds,
        public array $measureIds,
        public string $details,
        public ?string $calendarLabel,
        public bool $noMeasure,
        public string $noMeasureReason,
        public string $effectiveFromRaw,
        public string $effectiveToRaw,
        public ?bool $measuresEffective,
        public ?bool $familyClaimed,
        public string $familyClaimAttitude,
        public ?bool $registeredInSeneca,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            reportIds: array_values(array_filter($request->request->all('reports'), 'is_string')),
            measureIds: array_values(array_filter($request->request->all('measures'), 'is_string')),
            details: trim($request->request->getString('details')),
            calendarLabel: trim($request->request->getString('calendar_label')) ?: null,
            noMeasure: $request->request->getBoolean('no_measure_applied'),
            noMeasureReason: trim($request->request->getString('no_measure_reason')),
            effectiveFromRaw: trim($request->request->getString('effective_from')),
            effectiveToRaw: trim($request->request->getString('effective_to')),
            measuresEffective: self::parseBool($request->request->getString('measures_effective')),
            familyClaimed: self::parseBool($request->request->getString('family_claimed')),
            familyClaimAttitude: trim($request->request->getString('family_claim_attitude')),
            registeredInSeneca: self::parseBool($request->request->getString('registered_in_seneca')),
        );
    }

    /** @return array<string, mixed> */
    public function toTemplateArray(): array
    {
        return [
            'reports'             => $this->reportIds,
            'measureIds'          => $this->measureIds,
            'details'             => $this->details,
            'calendarLabel'       => $this->calendarLabel,
            'noMeasure'           => $this->noMeasure,
            'noMeasureReason'     => $this->noMeasureReason,
            'effectiveFrom'       => $this->effectiveFromRaw,
            'effectiveTo'         => $this->effectiveToRaw,
            'measuresEffective'   => $this->measuresEffective,
            'familyClaimed'       => $this->familyClaimed,
            'familyClaimAttitude' => $this->familyClaimAttitude,
            'registeredInSeneca'  => $this->registeredInSeneca,
        ];
    }

    private static function parseBool(string $raw): ?bool
    {
        return match ($raw) {
            '1'     => true,
            '0'     => false,
            default => null,
        };
    }
}
