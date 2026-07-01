<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del modo tablón: duración de semana actual y siguiente (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'board.current_week_seconds', 'integer', '15', 1, 1, 0, 0, 3600),
                (UNHEX(REPLACE(UUID(), '-', '')), 'board.next_week_seconds',    'integer', '5',  1, 1, 0, 0, 3600)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('board.current_week_seconds', 'board.next_week_seconds')");
    }
}
