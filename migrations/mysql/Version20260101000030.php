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
        return 'Añade id propio y materia (subject) a group_teacher, y crea time_slot / time_slot_teacher para los tramos horarios (MySQL / MariaDB)';
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

        $this->addSql(<<<'SQL'
            CREATE TABLE time_slot (
                id                BINARY(16)   NOT NULL,
                academic_year_id  BINARY(16)   NOT NULL,
                name              VARCHAR(255) NOT NULL,
                day_of_week       SMALLINT     NOT NULL,
                start_time        TIME         NOT NULL,
                end_time          TIME         NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_ts_year ON time_slot (academic_year_id)');
        $this->addSql('ALTER TABLE time_slot ADD CONSTRAINT FK_ts_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE time_slot_teacher (
                time_slot_id BINARY(16) NOT NULL,
                teacher_id   BINARY(16) NOT NULL,
                PRIMARY KEY(time_slot_id, teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_tst_time_slot ON time_slot_teacher (time_slot_id)');
        $this->addSql('CREATE INDEX IDX_tst_teacher   ON time_slot_teacher (teacher_id)');
        $this->addSql('ALTER TABLE time_slot_teacher ADD CONSTRAINT FK_tst_time_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_slot_teacher ADD CONSTRAINT FK_tst_teacher   FOREIGN KEY (teacher_id)   REFERENCES teacher(id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE time_slot_teacher DROP FOREIGN KEY FK_tst_time_slot');
        $this->addSql('ALTER TABLE time_slot_teacher DROP FOREIGN KEY FK_tst_teacher');
        $this->addSql('DROP TABLE time_slot_teacher');
        $this->addSql('ALTER TABLE time_slot DROP FOREIGN KEY FK_ts_year');
        $this->addSql('DROP TABLE time_slot');

        $this->addSql('DROP INDEX UNIQ_gt_group_teacher_subject ON group_teacher');
        $this->addSql('ALTER TABLE group_teacher DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE group_teacher DROP COLUMN subject');
        $this->addSql('ALTER TABLE group_teacher DROP COLUMN id');
        $this->addSql('ALTER TABLE group_teacher ADD PRIMARY KEY (group_id, teacher_id)');
    }
}
