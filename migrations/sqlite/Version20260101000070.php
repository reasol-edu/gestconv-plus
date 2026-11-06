<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000070 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal no docente: teacher.non_teaching y su asociación a los centros sin curso académico (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE teacher ADD COLUMN non_teaching BOOLEAN NOT NULL DEFAULT 0');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_non_teaching_staff (
                educational_centre_id CHAR(36) NOT NULL,
                teacher_id            CHAR(36) NOT NULL,
                PRIMARY KEY (educational_centre_id, teacher_id),
                CONSTRAINT fk_ecnt_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_ecnt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ecnt_centre ON educational_centre_non_teaching_staff (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_ecnt_teacher ON educational_centre_non_teaching_staff (teacher_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE educational_centre_non_teaching_staff');
        $this->addSql('ALTER TABLE teacher DROP COLUMN non_teaching');
    }
}
