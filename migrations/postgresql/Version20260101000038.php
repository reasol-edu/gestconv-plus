<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea school_event y school_event_group para el catálogo de eventos de centro (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event (
                id                UUID                        NOT NULL,
                academic_year_id  UUID                        NOT NULL,
                date              DATE                        NOT NULL,
                start_time        TIME(0) WITHOUT TIME ZONE   NOT NULL,
                end_time          TIME(0) WITHOUT TIME ZONE   NOT NULL,
                name              VARCHAR(255)                NOT NULL,
                description       TEXT                        DEFAULT NULL,
                url               VARCHAR(500)                DEFAULT NULL,
                general           BOOLEAN                     NOT NULL DEFAULT FALSE,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_school_event_year ON school_event (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_school_event_date ON school_event (date)');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_school_event_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_group (
                school_event_id  UUID NOT NULL,
                group_id         UUID NOT NULL,
                PRIMARY KEY(school_event_id, group_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_seg_school_event ON school_event_group (school_event_id)');
        $this->addSql('CREATE INDEX IDX_seg_group        ON school_event_group (group_id)');
        $this->addSql('ALTER TABLE school_event_group ADD CONSTRAINT FK_seg_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE school_event_group ADD CONSTRAINT FK_seg_group        FOREIGN KEY (group_id)        REFERENCES "group"(id)     ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE school_event_group DROP CONSTRAINT FK_seg_school_event');
        $this->addSql('ALTER TABLE school_event_group DROP CONSTRAINT FK_seg_group');
        $this->addSql('DROP TABLE school_event_group');

        $this->addSql('ALTER TABLE school_event DROP CONSTRAINT FK_school_event_year');
        $this->addSql('DROP TABLE school_event');
    }
}
