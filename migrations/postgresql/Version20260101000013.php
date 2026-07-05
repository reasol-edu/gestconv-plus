<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de avisos por correo electrónico de partes y sanciones (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices) VALUES
                (gen_random_uuid(), 'notifications.email_report_created',              'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_report_notified',             'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_report_modified',             'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_report_deleted',              'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_report_prescribed',           'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_report_sanctioned',           'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_sanction_notified',           'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.email_report_sanctionable_committee', 'choice', 'none', TRUE, TRUE, FALSE, NULL, NULL, 'none,committee')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN (
            'notifications.email_report_created',
            'notifications.email_report_notified',
            'notifications.email_report_modified',
            'notifications.email_report_deleted',
            'notifications.email_report_prescribed',
            'notifications.email_report_sanctioned',
            'notifications.email_sanction_notified',
            'notifications.email_report_sanctionable_committee'
        )");
    }
}
