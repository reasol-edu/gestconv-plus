<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes para adjuntar el PDF del parte o la sanción a los avisos por correo (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-00000000001b', 'notifications.email_report_attach_pdf',   'boolean', 'false', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 50, 90),
            ('00000000-0000-4000-8000-00000000001c', 'notifications.email_sanction_attach_pdf', 'boolean', 'false', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 50, 100)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.email_report_attach_pdf', 'notifications.email_sanction_attach_pdf')");
    }
}
