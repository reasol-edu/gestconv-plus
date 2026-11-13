<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Una copia de seguridad guardada en el servidor, con lo que se sabe de ella sin abrirla más que para leer su manifiesto.
 * `problems` son los motivos por los que no se podría restaurar en esta base de datos (vacío = se puede).
 */
final readonly class StoredBackup
{
    /** @param list<string> $problems */
    public function __construct(
        public string $name,
        public int $size,
        public int $modifiedAt,
        public ?BackupManifest $manifest,
        public array $problems,
    ) {}

    public function isRestorable(): bool
    {
        return $this->manifest !== null && $this->problems === [];
    }
}
