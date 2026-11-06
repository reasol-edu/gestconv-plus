<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000070 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal no docente: teacher.non_teaching y su asociación a los centros sin curso académico (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE teacher ADD non_teaching BOOLEAN DEFAULT FALSE NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_non_teaching_staff (
                educational_centre_id UUID NOT NULL,
                teacher_id            UUID NOT NULL,
                PRIMARY KEY (educational_centre_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_ecnt_centre ON educational_centre_non_teaching_staff (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_ecnt_teacher ON educational_centre_non_teaching_staff (teacher_id)');
        $this->addSql('ALTER TABLE educational_centre_non_teaching_staff ADD CONSTRAINT fk_ecnt_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE educational_centre_non_teaching_staff ADD CONSTRAINT fk_ecnt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE educational_centre_non_teaching_staff');
        $this->addSql('ALTER TABLE teacher DROP non_teaching');
    }
}
