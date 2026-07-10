<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de pie de contenido personalizado para partes y sanciones (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.incident_footer', 'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 110),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.sanction_footer', 'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 120)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('reports.incident_footer', 'reports.sanction_footer')");
    }
}
