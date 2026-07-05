<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de avisos por correo electrónico de partes y sanciones (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices) VALUES
            ('00000000-0000-4000-8000-000000000008', 'notifications.email_report_created',              'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-000000000009', 'notifications.email_report_notified',             'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-00000000000a', 'notifications.email_report_modified',             'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-00000000000b', 'notifications.email_report_deleted',              'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-00000000000c', 'notifications.email_report_prescribed',            'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-00000000000d', 'notifications.email_report_sanctioned',            'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-00000000000e', 'notifications.email_sanction_notified',            'choice', 'none', 1, 1, 0, NULL, NULL, 'none,report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-00000000000f', 'notifications.email_report_sanctionable_committee', 'choice', 'none', 1, 1, 0, NULL, NULL, 'none,committee')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
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
