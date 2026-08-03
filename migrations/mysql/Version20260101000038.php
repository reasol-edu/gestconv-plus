<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea school_event y school_event_group para el catálogo de eventos de centro (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event (
                id                BINARY(16)    NOT NULL,
                academic_year_id  BINARY(16)    NOT NULL,
                date              DATE          NOT NULL,
                start_time        TIME          NOT NULL,
                end_time          TIME          NOT NULL,
                name              VARCHAR(255)  NOT NULL,
                description       LONGTEXT      DEFAULT NULL,
                url               VARCHAR(500)  DEFAULT NULL,
                general           TINYINT(1)    NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_school_event_year ON school_event (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_school_event_date ON school_event (date)');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_school_event_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_group (
                school_event_id  BINARY(16) NOT NULL,
                group_id         BINARY(16) NOT NULL,
                PRIMARY KEY(school_event_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_seg_school_event ON school_event_group (school_event_id)');
        $this->addSql('CREATE INDEX IDX_seg_group        ON school_event_group (group_id)');
        $this->addSql('ALTER TABLE school_event_group ADD CONSTRAINT FK_seg_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE school_event_group ADD CONSTRAINT FK_seg_group        FOREIGN KEY (group_id)        REFERENCES `group`(id)      ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE school_event_group DROP FOREIGN KEY FK_seg_school_event');
        $this->addSql('ALTER TABLE school_event_group DROP FOREIGN KEY FK_seg_group');
        $this->addSql('DROP TABLE school_event_group');

        $this->addSql('ALTER TABLE school_event DROP FOREIGN KEY FK_school_event_year');
        $this->addSql('DROP TABLE school_event');
    }
}
