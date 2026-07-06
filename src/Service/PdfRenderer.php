<?php

declare(strict_types=1);

namespace App\Service;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\WatermarkText;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class PdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Renders a Twig template to a PDF response via mPDF, with a shared running
     * header/footer (pdf/_header.html.twig, pdf/_footer.html.twig).
     *
     * @param array<string, mixed> $context        Must include 'centre' (EducationalCentre); merged into header/footer/content.
     * @param PdfHeader|null       $header         Custom header content and top margin; falls back to pdfTitle / centre name.
     * @param bool                 $draftWatermark Shows a diagonal "BORRADOR" watermark on every page; used while the report/sanction hasn't been notified to the family yet.
     */
    public function render(
        string $template,
        array $context,
        string $title,
        string $filename,
        bool $inline = true,
        ?PdfHeader $header = null,
        bool $draftWatermark = false,
    ): Response {
        $context += [
            'pdfTitle'       => $title,
            'pdfGeneratedAt' => new \DateTimeImmutable(),
            'headerLeft'     => $header?->leftHtml,
            'headerRight'    => $header?->rightHtml,
        ];

        $mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => $header->marginTopMm ?? 22,
            'margin_bottom' => 18,
            'margin_header' => 8,
            'margin_footer' => 8,
            'tempDir'       => sys_get_temp_dir(),
        ]);

        if ($draftWatermark) {
            $mpdf->SetWatermarkText(new WatermarkText(
                mb_strtoupper($this->translator->trans('pdf.watermark.draft', [], 'admin')),
                120,
                45,
                '#999999',
                0.15,
                'dejavusans',
            ));
            $mpdf->showWatermarkText = true;
        }

        $mpdf->SetHTMLHeader($this->twig->render('pdf/_header.html.twig', $context));
        $mpdf->SetHTMLFooter($this->twig->render('pdf/_footer.html.twig', $context));
        $mpdf->WriteHTML($this->twig->render($template, $context));

        $content = $mpdf->Output('', Destination::STRING_RETURN);
        if (!is_string($content)) {
            throw new \RuntimeException('mPDF no devolvió el contenido del PDF esperado.');
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        ));

        return $response;
    }
}
