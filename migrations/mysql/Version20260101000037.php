<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade el ajuste de aviso por correo "Nota que implica registro de parte" (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position, choices) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_daily_note_threshold', 'choice', 'none', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 50, 130, 'none,group_tutor,admin,both')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` = 'notifications.email_daily_note_threshold'");
    }
}
