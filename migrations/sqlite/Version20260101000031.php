<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea absence, activity, activity_attachment y activity_group_teacher para la gestión de ausencias previstas del profesorado (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE absence (
                id                CHAR(36) NOT NULL,
                teacher_id        CHAR(36) NOT NULL,
                academic_year_id  CHAR(36) NOT NULL,
                start_date        DATE     NOT NULL,
                end_date          DATE     NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_absence_teacher FOREIGN KEY (teacher_id)       REFERENCES teacher(id),
                CONSTRAINT FK_absence_year    FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_absence_teacher ON absence (teacher_id)');
        $this->addSql('CREATE INDEX IDX_absence_year    ON absence (academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity (
                id            CHAR(36)     NOT NULL,
                absence_id    CHAR(36)     NOT NULL,
                time_slot_id  CHAR(36)     NOT NULL,
                date          DATE         NOT NULL,
                description   CLOB         NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_activity_absence   FOREIGN KEY (absence_id)   REFERENCES absence(id) ON DELETE CASCADE,
                CONSTRAINT FK_activity_time_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_activity_absence   ON activity (absence_id)');
        $this->addSql('CREATE INDEX IDX_activity_time_slot ON activity (time_slot_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_attachment (
                id           CHAR(36)      NOT NULL,
                activity_id  CHAR(36)      NOT NULL,
                filename     VARCHAR(255)  NOT NULL,
                mime_type    VARCHAR(100)  NOT NULL,
                size         INTEGER       NOT NULL,
                content      BLOB          NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_activity_attachment_activity FOREIGN KEY (activity_id) REFERENCES activity(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_activity_attachment_activity ON activity_attachment (activity_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_group_teacher (
                activity_id       CHAR(36) NOT NULL,
                group_teacher_id  CHAR(36) NOT NULL,
                PRIMARY KEY(activity_id, group_teacher_id),
                CONSTRAINT FK_agt_activity      FOREIGN KEY (activity_id)      REFERENCES activity(id) ON DELETE CASCADE,
                CONSTRAINT FK_agt_group_teacher FOREIGN KEY (group_teacher_id) REFERENCES group_teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_agt_activity      ON activity_group_teacher (activity_id)');
        $this->addSql('CREATE INDEX IDX_agt_group_teacher ON activity_group_teacher (group_teacher_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE activity_group_teacher');
        $this->addSql('DROP TABLE activity_attachment');
        $this->addSql('DROP TABLE activity');
        $this->addSql('DROP TABLE absence');
    }
}
