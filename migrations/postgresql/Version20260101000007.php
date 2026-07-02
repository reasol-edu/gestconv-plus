<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade los perfiles de comisión de convivencia y orientador (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_committee_members (
                educational_centre_id UUID NOT NULL,
                teacher_id            UUID NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ecc_centre  ON educational_centre_committee_members (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_ecc_teacher ON educational_centre_committee_members (teacher_id)');
        $this->addSql('ALTER TABLE educational_centre_committee_members ADD CONSTRAINT FK_ecc_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_committee_members ADD CONSTRAINT FK_ecc_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_counselors (
                educational_centre_id UUID NOT NULL,
                teacher_id            UUID NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ecs_centre  ON educational_centre_counselors (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_ecs_teacher ON educational_centre_counselors (teacher_id)');
        $this->addSql('ALTER TABLE educational_centre_counselors ADD CONSTRAINT FK_ecs_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_counselors ADD CONSTRAINT FK_ecs_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE educational_centre_counselors');
        $this->addSql('DROP TABLE educational_centre_committee_members');
    }
}
