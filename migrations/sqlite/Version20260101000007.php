<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade los perfiles de comisión de convivencia y orientador (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_committee_members (
                educational_centre_id CHAR(36) NOT NULL,
                teacher_id            CHAR(36) NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id),
                CONSTRAINT FK_ecc_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE,
                CONSTRAINT FK_ecc_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ecc_centre  ON educational_centre_committee_members (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_ecc_teacher ON educational_centre_committee_members (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_counselors (
                educational_centre_id CHAR(36) NOT NULL,
                teacher_id            CHAR(36) NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id),
                CONSTRAINT FK_ecs_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE,
                CONSTRAINT FK_ecs_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ecs_centre  ON educational_centre_counselors (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_ecs_teacher ON educational_centre_counselors (teacher_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE IF EXISTS educational_centre_counselors');
        $this->addSql('DROP TABLE IF EXISTS educational_centre_committee_members');
    }
}
