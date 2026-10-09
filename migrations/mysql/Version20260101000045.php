<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prescripción de sanciones no notificadas: columna prescribed_at y ajustes (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE sanction ADD COLUMN prescribed_at DATE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.sanction_auto_prescribe_days',      'integer', '0',    1, 1, 0, 0, 365, NULL, 'settings.category.notifications', 40, 35),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.sanction_prescription_warning_days', 'integer', '7',    1, 1, 1, 0, 365, NULL, 'settings.category.notifications', 40, 45),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_sanction_prescribed',          'choice',  'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both', 'settings.category.email_alerts', 50, 75)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('notifications.sanction_auto_prescribe_days', 'notifications.sanction_prescription_warning_days', 'notifications.email_sanction_prescribed')");
        $this->addSql('ALTER TABLE sanction DROP COLUMN prescribed_at');
    }
}
