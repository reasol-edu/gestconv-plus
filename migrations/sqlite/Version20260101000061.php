<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000061 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estudiantes asociados a las actividades del centro: school_event_student (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_student (
                school_event_id  CHAR(36) NOT NULL,
                student_id       CHAR(36) NOT NULL,
                PRIMARY KEY(school_event_id, student_id),
                CONSTRAINT FK_ses_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE,
                CONSTRAINT FK_ses_student      FOREIGN KEY (student_id)      REFERENCES student(id)      ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ses_school_event ON school_event_student (school_event_id)');
        $this->addSql('CREATE INDEX IDX_ses_student      ON school_event_student (student_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE school_event_student');
    }
}
