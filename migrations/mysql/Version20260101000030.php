<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade id propio y materia (subject) a group_teacher, permitiendo varias asignaturas por docente y grupo (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE group_teacher ADD COLUMN id BINARY(16) NULL');
        $this->addSql("UPDATE group_teacher SET id = UNHEX(REPLACE(UUID(), '-', ''))");
        $this->addSql("ALTER TABLE group_teacher ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT ''");

        $this->addSql('ALTER TABLE group_teacher DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE group_teacher MODIFY COLUMN id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE group_teacher ADD PRIMARY KEY (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_gt_group_teacher_subject ON group_teacher (group_id, teacher_id, subject)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP INDEX UNIQ_gt_group_teacher_subject ON group_teacher');
        $this->addSql('ALTER TABLE group_teacher DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE group_teacher DROP COLUMN subject');
        $this->addSql('ALTER TABLE group_teacher DROP COLUMN id');
        $this->addSql('ALTER TABLE group_teacher ADD PRIMARY KEY (group_id, teacher_id)');
    }
}
