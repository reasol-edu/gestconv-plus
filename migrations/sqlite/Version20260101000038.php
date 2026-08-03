<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea school_event y school_event_group para el catálogo de eventos de centro (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event (
                id                CHAR(36)      NOT NULL,
                academic_year_id  CHAR(36)      NOT NULL,
                date              DATE          NOT NULL,
                start_time        TIME          NOT NULL,
                end_time          TIME          NOT NULL,
                name              VARCHAR(255)  NOT NULL,
                description       CLOB          DEFAULT NULL,
                url               VARCHAR(500)  DEFAULT NULL,
                general           INTEGER       NOT NULL DEFAULT 0,
                PRIMARY KEY(id),
                CONSTRAINT FK_school_event_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_school_event_year ON school_event (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_school_event_date ON school_event (date)');

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_group (
                school_event_id  CHAR(36) NOT NULL,
                group_id         CHAR(36) NOT NULL,
                PRIMARY KEY(school_event_id, group_id),
                CONSTRAINT FK_seg_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE,
                CONSTRAINT FK_seg_group        FOREIGN KEY (group_id)        REFERENCES "group"(id)      ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_seg_school_event ON school_event_group (school_event_id)');
        $this->addSql('CREATE INDEX IDX_seg_group        ON school_event_group (group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE school_event_group');
        $this->addSql('DROP TABLE school_event');
    }
}
