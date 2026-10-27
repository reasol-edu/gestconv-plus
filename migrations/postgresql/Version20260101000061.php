<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000061 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estudiantes asociados a las actividades del centro: school_event_student (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_student (
                school_event_id  UUID NOT NULL,
                student_id       UUID NOT NULL,
                PRIMARY KEY(school_event_id, student_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ses_school_event ON school_event_student (school_event_id)');
        $this->addSql('CREATE INDEX IDX_ses_student      ON school_event_student (student_id)');
        $this->addSql('ALTER TABLE school_event_student ADD CONSTRAINT FK_ses_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE school_event_student ADD CONSTRAINT FK_ses_student      FOREIGN KEY (student_id)      REFERENCES student(id)      ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE school_event_student DROP CONSTRAINT FK_ses_school_event');
        $this->addSql('ALTER TABLE school_event_student DROP CONSTRAINT FK_ses_student');
        $this->addSql('DROP TABLE school_event_student');
    }
}
