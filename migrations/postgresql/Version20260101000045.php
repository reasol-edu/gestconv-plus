<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prescripción de sanciones no notificadas: columna prescribed_at y ajustes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE sanction ADD prescribed_at DATE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'notifications.sanction_auto_prescribe_days',      'integer', '0',    TRUE, TRUE, FALSE, 0, 365, NULL, 'settings.category.notifications', 40, 35),
                (gen_random_uuid(), 'notifications.sanction_prescription_warning_days', 'integer', '7',    TRUE, TRUE, TRUE,  0, 365, NULL, 'settings.category.notifications', 40, 45),
                (gen_random_uuid(), 'notifications.email_sanction_prescribed',          'choice',  'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both', 'settings.category.email_alerts', 50, 75)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.sanction_auto_prescribe_days', 'notifications.sanction_prescription_warning_days', 'notifications.email_sanction_prescribed')");
        $this->addSql('ALTER TABLE sanction DROP prescribed_at');
    }
}
