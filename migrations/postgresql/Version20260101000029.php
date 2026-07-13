<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reemplaza Programme + ProgrammeYear por Course con relación ManyToOne en Group (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        // 1. Crear tabla course
        $this->addSql(<<<'SQL'
            CREATE TABLE course (
                id               UUID         NOT NULL,
                academic_year_id UUID         NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          TEXT         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_course_year ON course (academic_year_id)');
        $this->addSql('ALTER TABLE course ADD CONSTRAINT FK_course_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // 2. Añadir course_id a group (nullable temporalmente)
        $this->addSql('ALTER TABLE "group" ADD COLUMN course_id UUID NULL');
        $this->addSql('CREATE INDEX IDX_group_course ON "group" (course_id)');
        $this->addSql('ALTER TABLE "group" ADD CONSTRAINT FK_group_course FOREIGN KEY (course_id) REFERENCES course(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // 3. Eliminar FK antigua y columna programme_year_id
        $this->addSql('ALTER TABLE "group" DROP CONSTRAINT FK_group_py');
        $this->addSql('DROP INDEX IDX_group_py');
        $this->addSql('ALTER TABLE "group" DROP COLUMN programme_year_id');

        // 4. Eliminar tablas obsoletas
        $this->addSql('ALTER TABLE programme_year DROP CONSTRAINT FK_py_programme');
        $this->addSql('DROP TABLE programme_year');
        $this->addSql('DROP TABLE programme');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE programme (
                id               UUID         NOT NULL,
                academic_year_id UUID         NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          TEXT         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_prog_year ON programme (academic_year_id)');
        $this->addSql('ALTER TABLE programme ADD CONSTRAINT FK_prog_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme_year (
                id           UUID         NOT NULL,
                programme_id UUID         NOT NULL,
                name         VARCHAR(255) NOT NULL,
                details      TEXT         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_py_programme ON programme_year (programme_id)');
        $this->addSql('ALTER TABLE programme_year ADD CONSTRAINT FK_py_programme FOREIGN KEY (programme_id) REFERENCES programme(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE "group" ADD COLUMN programme_year_id UUID NOT NULL');
        $this->addSql('CREATE INDEX IDX_group_py ON "group" (programme_year_id)');
        $this->addSql('ALTER TABLE "group" ADD CONSTRAINT FK_group_py FOREIGN KEY (programme_year_id) REFERENCES programme_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE "group" DROP CONSTRAINT FK_group_course');
        $this->addSql('DROP INDEX IDX_group_course');
        $this->addSql('ALTER TABLE "group" DROP COLUMN course_id');
        $this->addSql('ALTER TABLE course DROP CONSTRAINT FK_course_year');
        $this->addSql('DROP TABLE course');
    }
}
