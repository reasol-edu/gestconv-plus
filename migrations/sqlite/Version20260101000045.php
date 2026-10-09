<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prescripción de sanciones no notificadas: columna prescribed_at y ajustes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE sanction ADD COLUMN prescribed_at DATE DEFAULT NULL');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000041', 'notifications.sanction_auto_prescribe_days',      'integer', '0',    1, 1, 0, 0, 365, NULL, 'settings.category.notifications', 40, 35),
            ('00000000-0000-4000-8000-000000000042', 'notifications.sanction_prescription_warning_days', 'integer', '7',    1, 1, 1, 0, 365, NULL, 'settings.category.notifications', 40, 45),
            ('00000000-0000-4000-8000-000000000043', 'notifications.email_sanction_prescribed',          'choice',  'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both', 'settings.category.email_alerts', 50, 75)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.sanction_auto_prescribe_days', 'notifications.sanction_prescription_warning_days', 'notifications.email_sanction_prescribed')");
        $this->addSql('ALTER TABLE sanction DROP COLUMN prescribed_at');
    }
}
