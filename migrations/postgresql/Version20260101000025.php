<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de pie de contenido personalizado para partes y sanciones (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'reports.incident_footer', 'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 110),
                (gen_random_uuid(), 'reports.sanction_footer', 'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 120)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.incident_footer', 'reports.sanction_footer')");
    }
}
