<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade category, category_order y position a setting_definition, y agrupa los ajustes existentes (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE setting_definition ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT \'\'');
        $this->addSql('ALTER TABLE setting_definition ADD COLUMN category_order INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE setting_definition ADD COLUMN position INT NOT NULL DEFAULT 0');

        $this->addSql("UPDATE setting_definition SET category = 'settings.category.display',          category_order = 10, position = 10 WHERE `key` = 'page.size'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email',            category_order = 20, position = 10 WHERE `key` = 'email.notifications'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.board',             category_order = 30, position = 10 WHERE `key` = 'board.current_week_seconds'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.board',             category_order = 30, position = 20 WHERE `key` = 'board.next_week_seconds'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.notifications',     category_order = 40, position = 10 WHERE `key` = 'notifications.report_notifier'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.notifications',     category_order = 40, position = 20 WHERE `key` = 'notifications.sanction_notifier'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 10 WHERE `key` = 'notifications.email_report_created'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 20 WHERE `key` = 'notifications.email_report_notified'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 30 WHERE `key` = 'notifications.email_report_modified'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 40 WHERE `key` = 'notifications.email_report_deleted'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 50 WHERE `key` = 'notifications.email_report_prescribed'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 60 WHERE `key` = 'notifications.email_report_sanctioned'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 70 WHERE `key` = 'notifications.email_sanction_notified'");
        $this->addSql("UPDATE setting_definition SET category = 'settings.category.email_alerts',      category_order = 50, position = 80 WHERE `key` = 'notifications.email_report_sanctionable_committee'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE setting_definition DROP COLUMN position');
        $this->addSql('ALTER TABLE setting_definition DROP COLUMN category_order');
        $this->addSql('ALTER TABLE setting_definition DROP COLUMN category');
    }
}
