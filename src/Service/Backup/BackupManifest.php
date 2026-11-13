<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Lo que dice una copia de seguridad de sí misma (`manifest.json`): de qué versión de la aplicación y del esquema es, en qué
 * plataforma se hizo y cuántas filas lleva cada tabla.
 */
final readonly class BackupManifest
{
    public const int FORMAT = 1;

    /**
     * @param array<string, array{rows: int, columns: list<string>}> $tables
     * @param list<string>                                           $excluded
     */
    public function __construct(
        public int $format,
        public string $appVersion,
        public ?string $schemaVersion,
        public string $platform,
        public string $createdAt,
        public array $tables,
        public array $excluded,
    ) {}

    public function totalRows(): int
    {
        return array_sum(array_column($this->tables, 'rows'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format'        => $this->format,
            'app_version'   => $this->appVersion,
            'schema_version' => $this->schemaVersion,
            'platform'      => $this->platform,
            'created_at'    => $this->createdAt,
            'excluded'      => $this->excluded,
            'tables'        => $this->tables,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $tables = $data['tables'] ?? null;
        if (!is_array($tables) || !isset($data['format'], $data['platform'], $data['app_version'], $data['created_at'])) {
            throw new BackupException('El fichero no es una copia de seguridad de GestConv+ (el manifiesto está incompleto).');
        }

        $clean = [];
        foreach ($tables as $name => $info) {
            if (!is_string($name) || !is_array($info) || !isset($info['rows']) || !is_array($info['columns'] ?? null)) {
                throw new BackupException('El manifiesto de la copia de seguridad no es válido.');
            }
            $clean[$name] = ['rows' => self::int($info['rows']), 'columns' => self::strings($info['columns'])];
        }

        return new self(
            self::int($data['format']),
            self::string($data['app_version']),
            isset($data['schema_version']) ? self::string($data['schema_version']) : null,
            self::string($data['platform']),
            self::string($data['created_at']),
            $clean,
            self::strings(is_array($data['excluded'] ?? null) ? $data['excluded'] : []),
        );
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new BackupException('El manifiesto de la copia de seguridad no es válido.');
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new BackupException('El manifiesto de la copia de seguridad no es válido.');
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = self::string($value);
        }

        return $out;
    }
}
