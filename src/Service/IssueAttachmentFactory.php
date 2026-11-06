<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\IssueAttachment;
use App\Entity\Teacher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Crea los adjuntos de una incidencia a partir de los ficheros subidos: comprueba el tamaño (límites de subida), el tipo
 * (lista cerrada, detectado por el contenido y no por lo que diga el navegador; SVG no se admite porque puede llevar
 * código) y, en las imágenes, que lo sean de verdad, y genera su miniatura con GD si está disponible.
 */
class IssueAttachmentFactory
{
    /** Imágenes que se pueden ver en línea y de las que se genera miniatura. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public const DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain',
    ];

    /** Lado mayor de la miniatura, en píxeles. */
    private const THUMBNAIL_SIZE = 320;

    /** Más píxeles que esto (≈ 40 megapíxeles) no se decodifican: ocuparían demasiada memoria. */
    private const MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly UploadLimits $limits,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Los avisos (ya traducidos) de los ficheros que no se admitirían: demasiado grandes, de un tipo no permitido o
     * imágenes que no lo son. Sirve para validar antes de guardar nada.
     *
     * @param iterable<mixed> $files los ficheros del campo múltiple del formulario
     * @return list<string>
     */
    public function errors(iterable $files): array
    {
        $problems = $this->limits->validate($files);
        if ($problems !== []) {
            return $problems;
        }

        $errors = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $error = $this->inspect($file)['error'];
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * @param iterable<mixed> $files los ficheros del campo múltiple del formulario
     * @return array{attachments: list<IssueAttachment>, errors: list<string>} los adjuntos listos para guardar y los avisos ya traducidos
     */
    public function build(Issue $issue, ?Teacher $uploadedBy, iterable $files, \DateTimeImmutable $now): array
    {
        $problems = $this->limits->validate($files);
        if ($problems !== []) {
            return ['attachments' => [], 'errors' => $problems];
        }

        $attachments = [];
        $errors      = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $checked = $this->inspect($file);
            if ($checked['error'] !== null || $checked['mime'] === null || $checked['content'] === null) {
                $errors[] = $checked['error'] ?? '';

                continue;
            }

            $attachments[] = new IssueAttachment(
                $issue,
                $uploadedBy,
                mb_substr($file->getClientOriginalName(), 0, 255),
                $checked['mime'],
                strlen($checked['content']),
                $checked['content'],
                in_array($checked['mime'], self::IMAGE_TYPES, true) ? $this->thumbnail($checked['content']) : null,
                $now,
            );
        }

        return ['attachments' => $attachments, 'errors' => array_values(array_filter($errors))];
    }

    /**
     * @return array{mime: ?string, content: ?string, error: ?string}
     */
    private function inspect(UploadedFile $file): array
    {
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        if (!in_array($mime, [...self::IMAGE_TYPES, ...self::DOCUMENT_TYPES], true)) {
            return ['mime' => null, 'content' => null, 'error' => $this->translator->trans('issue.error.attachment_type', ['%filename%' => $file->getClientOriginalName()], 'admin')];
        }

        $content = (string) file_get_contents($file->getPathname());
        if (in_array($mime, self::IMAGE_TYPES, true) && @getimagesizefromstring($content) === false) {
            return ['mime' => null, 'content' => null, 'error' => $this->translator->trans('issue.error.attachment_not_image', ['%filename%' => $file->getClientOriginalName()], 'admin')];
        }

        return ['mime' => $mime, 'content' => $content, 'error' => null];
    }

    /**
     * Miniatura JPEG (lado mayor de 320 px, orientación corregida) o null si GD no está disponible o la imagen no se puede
     * decodificar con seguridad.
     */
    public function thumbnail(string $content): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $info = @getimagesizefromstring($content);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($content);
        if ($source === false) {
            return null;
        }

        // La orientación de las fotos de móvil viene en EXIF (solo JPEG): se corrige para que la miniatura salga derecha.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($content));
            $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                if ($rotated !== false) {
                    $source = $rotated;
                }
            }
        }

        $width  = imagesx($source);
        $height = imagesy($source);
        $scale  = min(1.0, self::THUMBNAIL_SIZE / max($width, $height));
        $w      = max(1, (int) round($width * $scale));
        $h      = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($w, $h);
        // Fondo blanco: los PNG y GIF transparentes no se quedan en negro al pasar a JPEG.
        imagefill($thumb, 0, 0, (int) imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagejpeg($thumb, null, 82);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : $jpeg;
    }
}
