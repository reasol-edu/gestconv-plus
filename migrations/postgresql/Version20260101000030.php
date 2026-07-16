<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade id propio y materia (subject) a group_teacher, permitiendo varias asignaturas por docente y grupo (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE group_teacher ADD COLUMN id UUID NULL');
        $this->addSql('UPDATE group_teacher SET id = gen_random_uuid()');
        $this->addSql("ALTER TABLE group_teacher ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT ''");

        $this->addSql('ALTER TABLE group_teacher DROP CONSTRAINT group_teacher_pkey');
        $this->addSql('ALTER TABLE group_teacher ALTER COLUMN id SET NOT NULL');
        $this->addSql('ALTER TABLE group_teacher ADD PRIMARY KEY (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_gt_group_teacher_subject ON group_teacher (group_id, teacher_id, subject)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP INDEX UNIQ_gt_group_teacher_subject');
        $this->addSql('ALTER TABLE group_teacher DROP CONSTRAINT group_teacher_pkey');
        $this->addSql('ALTER TABLE group_teacher DROP COLUMN subject');
        $this->addSql('ALTER TABLE group_teacher DROP COLUMN id');
        $this->addSql('ALTER TABLE group_teacher ADD PRIMARY KEY (group_id, teacher_id)');
    }
}
