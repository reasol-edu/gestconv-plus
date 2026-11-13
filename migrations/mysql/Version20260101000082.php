<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000082 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fechas de comienzo y de fin del curso académico (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql("ALTER TABLE academic_year ADD start_date DATE NOT NULL DEFAULT '1970-01-01', ADD end_date DATE NOT NULL DEFAULT '1970-01-01'");

        // Los cursos que ya existen: del 15 de septiembre del año con el que empieza su nombre («2025-2026» → 2025) al 14 de
        // septiembre del siguiente; si el nombre no trae ningún año, el curso que contiene la fecha de hoy.
        $today = \Symfony\Component\Clock\now();
        foreach ($this->connection->fetchAllAssociative('SELECT id, name FROM academic_year') as $row) {
            if (preg_match('/(?<!\d)(\d{4})(?!\d)/', (string) $row['name'], $match) === 1) {
                $startYear = (int) $match[1];
            } else {
                $startYear = (int) $today->format('Y') - ($today->format('m-d') < '09-15' ? 1 : 0);
            }
            // El id es BINARY(16): se envía como binario.
            $this->addSql(
                'UPDATE academic_year SET start_date = ?, end_date = ? WHERE id = ?',
                [sprintf('%04d-09-15', $startYear), sprintf('%04d-09-14', $startYear + 1), $row['id']],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY]
            );
        }

        $this->addSql('ALTER TABLE academic_year ALTER start_date DROP DEFAULT, ALTER end_date DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('ALTER TABLE academic_year DROP start_date, DROP end_date');
    }
}
