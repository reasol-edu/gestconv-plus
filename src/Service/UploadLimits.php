<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Límites de subida de ficheros: los configurados en la aplicación (ajustes globales
 * «uploads.*») acotados por los de PHP (upload_max_filesize, post_max_size, max_file_uploads),
 * que el servidor impone por encima de lo que diga la aplicación.
 *
 * PHP descarta en silencio lo que supera sus límites (el fichero llega con un código de error,
 * o el cuerpo entero del envío desaparece, token CSRF incluido): este servicio calcula los
 * máximos reales y traduce cada incidencia a un aviso claro para el usuario.
 */
class UploadLimits
{
    public const DEFAULT_FILE_MB  = 10;
    public const DEFAULT_TOTAL_MB = 50;

    /** Margen para lo que no son ficheros en un envío multipart (campos, cabeceras y delimitadores). */
    private const MULTIPART_OVERHEAD = 256 * 1024;

    private const MIB = 1024 * 1024;

    public function __construct(
        private readonly AppSettingsInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Tamaño máximo real de un fichero, en bytes. */
    public function maxFileBytes(): int
    {
        $limit = $this->configuredBytes('uploads.max_file_size_mb', self::DEFAULT_FILE_MB);

        $iniFile = $this->iniBytes('upload_max_filesize');
        if ($iniFile > 0) {
            $limit = min($limit, $iniFile);
        }

        $postMax = $this->iniBytes('post_max_size');
        if ($postMax > 0) {
            $limit = min($limit, max(1, $postMax - self::MULTIPART_OVERHEAD));
        }

        return $limit;
    }

    /** Tamaño máximo real de la suma de los ficheros de un envío, en bytes (nunca menor que el de un fichero). */
    public function maxTotalBytes(): int
    {
        $limit = $this->configuredBytes('uploads.max_total_size_mb', self::DEFAULT_TOTAL_MB);

        $postMax = $this->iniBytes('post_max_size');
        if ($postMax > 0) {
            $limit = min($limit, max(1, $postMax - self::MULTIPART_OVERHEAD));
        }

        return max($limit, $this->maxFileBytes());
    }

    /**
     * Número máximo de ficheros por envío. PHP descarta sin avisar los que superan max_file_uploads,
     * así que recibir exactamente ese número ya podría significar que se han perdido otros: el límite
     * seguro es uno menos.
     */
    public function maxFiles(): int
    {
        $ini = (int) $this->iniValue('max_file_uploads');

        return $ini > 0 ? max(1, $ini - 1) : 1000;
    }

    /** ¿El cuerpo de la petición supera post_max_size (PHP lo descarta entero, token CSRF incluido)? */
    public function isRequestTooLarge(Request $request): bool
    {
        $contentLength = $request->headers->get('Content-Length');
        $postMax       = $this->iniBytes('post_max_size');

        return $contentLength !== null && $postMax > 0 && (int) $contentLength > $postMax;
    }

    public function requestTooLargeMessage(Request $request): string
    {
        return $this->translator->trans('upload.error.request_too_large', [
            '%size%' => $this->formatSize((int) $request->headers->get('Content-Length')),
            '%max%'  => $this->formatSize($this->maxTotalBytes()),
        ], 'admin');
    }

    /**
     * Valida los ficheros de un campo múltiple. No ignora en silencio ninguno: cada fichero
     * incompleto, rechazado por PHP o demasiado grande produce un aviso, y también el total y el número.
     *
     * @param iterable<mixed> $files normalmente $request->files->all('campo')
     * @return list<string> avisos ya traducidos (vacío si todo es correcto)
     */
    public function validate(iterable $files): array
    {
        $errors = [];
        $total  = 0;
        $count  = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || $file->getError() === \UPLOAD_ERR_NO_FILE) {
                continue;
            }

            ++$count;

            $problem = $this->fileProblem($file);
            if ($problem !== null) {
                $errors[] = $problem;
                continue;
            }

            $total += (int) $file->getSize();
        }

        if ($count > $this->maxFiles()) {
            $errors[] = $this->translator->trans('upload.error.too_many_files', ['%max%' => $this->maxFiles()], 'admin');
        }

        if ($total > $this->maxTotalBytes()) {
            $errors[] = $this->translator->trans('upload.error.total_too_large', [
                '%total%' => $this->formatSize($total),
                '%max%'   => $this->formatSize($this->maxTotalBytes()),
            ], 'admin');
        }

        return $errors;
    }

    /**
     * Aviso sobre un fichero concreto (null si está bien o si no se ha elegido ninguno). Para los
     * formularios de un solo fichero y, internamente, para cada fichero de un campo múltiple.
     */
    public function fileProblem(mixed $file): ?string
    {
        if (!$file instanceof UploadedFile || $file->getError() === \UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $name = $file->getClientOriginalName();

        if (!$file->isValid()) {
            return match ($file->getError()) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => $this->translator->trans('upload.error.server_limit', [
                    '%filename%' => $name,
                    '%max%'      => $this->formatSize($this->maxFileBytes()),
                ], 'admin'),
                \UPLOAD_ERR_PARTIAL => $this->translator->trans('upload.error.partial', ['%filename%' => $name], 'admin'),
                default             => $this->translator->trans('upload.error.not_saved', ['%filename%' => $name], 'admin'),
            };
        }

        if ((int) $file->getSize() > $this->maxFileBytes()) {
            return $this->translator->trans('attachment.error.too_large', [
                '%filename%' => $name,
                '%max%'      => $this->formatSize($this->maxFileBytes()),
            ], 'admin');
        }

        return null;
    }

    /** «10 MB», «1,5 MB» o «300 KB». */
    public function formatSize(int $bytes): string
    {
        if ($bytes >= self::MIB) {
            return ($bytes % self::MIB === 0 ? (string) intdiv($bytes, self::MIB) : number_format($bytes / self::MIB, 1, ',', '')) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function configuredBytes(string $key, int $defaultMb): int
    {
        $value = $this->settings->getGlobal($key);
        $mb    = is_int($value) && $value > 0 ? $value : $defaultMb;

        return $mb * self::MIB;
    }

    /** Valor actual de una directiva de PHP (separado para poder sustituirlo en los tests: no se pueden cambiar en ejecución). */
    protected function iniValue(string $directive): string
    {
        return (string) ini_get($directive);
    }

    /** Valor en bytes de una directiva de tamaño de PHP (0 = sin límite o no definida). */
    private function iniBytes(string $directive): int
    {
        $value = trim($this->iniValue($directive));
        if ($value === '' || (int) $value <= 0) {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
