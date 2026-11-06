<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000070 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal no docente: teacher.non_teaching y su asociación a los centros sin curso académico (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE teacher ADD non_teaching TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_non_teaching_staff (
                educational_centre_id BINARY(16) NOT NULL,
                teacher_id            BINARY(16) NOT NULL,
                INDEX idx_ecnt_centre (educational_centre_id),
                INDEX idx_ecnt_teacher (teacher_id),
                PRIMARY KEY (educational_centre_id, teacher_id),
                CONSTRAINT fk_ecnt_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_ecnt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP TABLE educational_centre_non_teaching_staff');
        $this->addSql('ALTER TABLE teacher DROP non_teaching');
    }
}
