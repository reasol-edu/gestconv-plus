<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Perfil de coordinación de actividades (educational_centre_activity_coordinators) y autor de cada actividad (school_event.created_by_id) (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_activity_coordinators (
                educational_centre_id BINARY(16) NOT NULL,
                teacher_id            BINARY(16) NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_ecac_centre  ON educational_centre_activity_coordinators (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_ecac_teacher ON educational_centre_activity_coordinators (teacher_id)');
        $this->addSql('ALTER TABLE educational_centre_activity_coordinators ADD CONSTRAINT FK_ecac_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_activity_coordinators ADD CONSTRAINT FK_ecac_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE school_event ADD created_by_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_school_event_created_by ON school_event (created_by_id)');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_school_event_created_by FOREIGN KEY (created_by_id) REFERENCES teacher (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE school_event DROP FOREIGN KEY FK_school_event_created_by');
        $this->addSql('DROP INDEX IDX_school_event_created_by ON school_event');
        $this->addSql('ALTER TABLE school_event DROP COLUMN created_by_id');
        $this->addSql('DROP TABLE educational_centre_activity_coordinators');
    }
}
