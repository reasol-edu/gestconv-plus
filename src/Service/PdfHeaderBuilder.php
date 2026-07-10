<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Builds the running PDF header for a report type from the
 * 'reports.{type}_header_*' settings, resolving placeholders like
 * {title} or {student_name} against the given values.
 */
final class PdfHeaderBuilder
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rich_text')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    /**
     * @param 'incident'|'sanction'|'group_stats' $type
     * @param array<string, string|int>      $placeholders keys without braces, e.g. 'title' => 'Informe…'
     */
    public function build(string $type, EducationalCentre $centre, array $placeholders): PdfHeader
    {
        $replacements = [];
        foreach ($placeholders as $key => $value) {
            $replacements['{' . $key . '}'] = htmlspecialchars((string) $value, ENT_QUOTES);
        }

        $margin = $this->settings->getForCentre("reports.{$type}_header_margin", $centre);

        return new PdfHeader(
            $this->resolveSide("reports.{$type}_header_left", $centre, $replacements),
            $this->resolveSide("reports.{$type}_header_right", $centre, $replacements),
            is_int($margin) ? $margin : 22,
        );
    }

    /** @param array<string, string> $replacements */
    private function resolveSide(string $settingKey, EducationalCentre $centre, array $replacements): string
    {
        $raw = $this->settings->getForCentre($settingKey, $centre);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        // Se sanea antes de sustituir: los tokens {x} sobreviven como texto
        // plano y los valores sustituidos ya llegan escapados, de modo que
        // nunca se inyecta HTML no permitido en el encabezado.
        return strtr($this->sanitizer->sanitize($raw), $replacements);
    }
}
