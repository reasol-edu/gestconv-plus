<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reemplaza Programme + ProgrammeYear por Course con relación ManyToOne en Group (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        // 1. Crear tabla course
        $this->addSql(<<<'SQL'
            CREATE TABLE course (
                id               BINARY(16)   NOT NULL,
                academic_year_id BINARY(16)   NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          LONGTEXT     DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_course_year ON course (academic_year_id)');
        $this->addSql('ALTER TABLE course ADD CONSTRAINT FK_course_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)');

        // 2. Añadir course_id a group (temporalmente nullable para la migración; se actualiza a NOT NULL al final)
        $this->addSql('ALTER TABLE `group` ADD COLUMN course_id BINARY(16) NULL AFTER id');
        $this->addSql('CREATE INDEX IDX_group_course ON `group` (course_id)');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_group_course FOREIGN KEY (course_id) REFERENCES course(id)');

        // 3. Eliminar FK antigua y columna programme_year_id
        $this->addSql('ALTER TABLE `group` DROP FOREIGN KEY FK_group_py');
        $this->addSql('DROP INDEX IDX_group_py ON `group`');
        $this->addSql('ALTER TABLE `group` DROP COLUMN programme_year_id');

        // 4. Eliminar tablas obsoletas
        $this->addSql('ALTER TABLE programme_year DROP FOREIGN KEY FK_py_programme');
        $this->addSql('DROP TABLE programme_year');
        $this->addSql('DROP TABLE programme');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        // Recrea programme y programme_year
        $this->addSql(<<<'SQL'
            CREATE TABLE programme (
                id               BINARY(16)   NOT NULL,
                academic_year_id BINARY(16)   NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          LONGTEXT     DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_prog_year ON programme (academic_year_id)');
        $this->addSql('ALTER TABLE programme ADD CONSTRAINT FK_prog_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme_year (
                id           BINARY(16)   NOT NULL,
                programme_id BINARY(16)   NOT NULL,
                name         VARCHAR(255) NOT NULL,
                details      LONGTEXT     DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_py_programme ON programme_year (programme_id)');
        $this->addSql('ALTER TABLE programme_year ADD CONSTRAINT FK_py_programme FOREIGN KEY (programme_id) REFERENCES programme(id)');

        // Restaura programme_year_id en group
        $this->addSql('ALTER TABLE `group` ADD COLUMN programme_year_id BINARY(16) NOT NULL AFTER id');
        $this->addSql('CREATE INDEX IDX_group_py ON `group` (programme_year_id)');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_group_py FOREIGN KEY (programme_year_id) REFERENCES programme_year(id)');

        // Elimina course_id
        $this->addSql('ALTER TABLE `group` DROP FOREIGN KEY FK_group_course');
        $this->addSql('DROP INDEX IDX_group_course ON `group`');
        $this->addSql('ALTER TABLE `group` DROP COLUMN course_id');
        $this->addSql('ALTER TABLE course DROP FOREIGN KEY FK_course_year');
        $this->addSql('DROP TABLE course');
    }
}
