<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000082 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fechas de comienzo y de fin del curso académico (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // SQLite no permite añadir una columna NOT NULL sin valor por defecto, y el valor por defecto se queda en el esquema:
        // es lo único que se puede hacer sin reconstruir la tabla, y no afecta al mapeo (la aplicación siempre da las fechas).
        $this->addSql("ALTER TABLE academic_year ADD COLUMN start_date DATE NOT NULL DEFAULT '1970-01-01'");
        $this->addSql("ALTER TABLE academic_year ADD COLUMN end_date DATE NOT NULL DEFAULT '1970-01-01'");

        // Los cursos que ya existen: del 15 de septiembre del año con el que empieza su nombre («2025-2026» → 2025) al 14 de
        // septiembre del siguiente; si el nombre no trae ningún año, el curso que contiene la fecha de hoy.
        $today = \Symfony\Component\Clock\now();
        foreach ($this->connection->fetchAllAssociative('SELECT id, name FROM academic_year') as $row) {
            if (preg_match('/(?<!\d)(\d{4})(?!\d)/', (string) $row['name'], $match) === 1) {
                $startYear = (int) $match[1];
            } else {
                $startYear = (int) $today->format('Y') - ($today->format('m-d') < '09-15' ? 1 : 0);
            }
            // El id se vuelve a enviar tal como se leyó (texto con los 16 bytes del UUID), no como BLOB (ver la migración 050).
            $this->addSql(
                'UPDATE academic_year SET start_date = ?, end_date = ? WHERE id = ?',
                [sprintf('%04d-09-15', $startYear), sprintf('%04d-09-14', $startYear + 1), $row['id']]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE academic_year DROP COLUMN start_date');
        $this->addSql('ALTER TABLE academic_year DROP COLUMN end_date');
    }
}
