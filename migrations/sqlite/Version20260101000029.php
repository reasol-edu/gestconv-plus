<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reemplaza Programme + ProgrammeYear por Course con relación ManyToOne en Group (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // 1. Crear tabla course
        $this->addSql(<<<'SQL'
            CREATE TABLE course (
                id               CHAR(36)     NOT NULL,
                academic_year_id CHAR(36)     NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          CLOB         DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_course_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_course_year ON course (academic_year_id)');

        // 2. SQLite no permite ADD COLUMN con FK ni NOT NULL sin default. Recreamos "group".
        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->addSql(<<<'SQL'
            CREATE TABLE __group_new (
                id        CHAR(36)     NOT NULL,
                course_id CHAR(36)     NOT NULL DEFAULT '',
                name      VARCHAR(255) NOT NULL,
                details   CLOB         DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_group_course FOREIGN KEY (course_id) REFERENCES course(id)
            )
        SQL);
        $this->addSql('INSERT INTO __group_new (id, name, details) SELECT id, name, details FROM "group"');
        $this->addSql('DROP TABLE "group"');
        $this->addSql('ALTER TABLE __group_new RENAME TO "group"');
        $this->addSql('CREATE INDEX IDX_group_course ON "group" (course_id)');

        $this->addSql('PRAGMA foreign_keys = ON');

        // 3. Eliminar tablas obsoletas
        $this->addSql('DROP TABLE programme_year');
        $this->addSql('DROP TABLE programme');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('PRAGMA foreign_keys = OFF');

        // Recrea programme y programme_year
        $this->addSql(<<<'SQL'
            CREATE TABLE programme (
                id               CHAR(36)     NOT NULL,
                academic_year_id CHAR(36)     NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          CLOB         DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_prog_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_prog_year ON programme (academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme_year (
                id           CHAR(36)     NOT NULL,
                programme_id CHAR(36)     NOT NULL,
                name         VARCHAR(255) NOT NULL,
                details      CLOB         DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_py_programme FOREIGN KEY (programme_id) REFERENCES programme(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_py_programme ON programme_year (programme_id)');

        // Restaura group con programme_year_id y elimina course_id
        $this->addSql(<<<'SQL'
            CREATE TABLE __group_old (
                id                CHAR(36)     NOT NULL,
                programme_year_id CHAR(36)     NOT NULL DEFAULT '',
                name              VARCHAR(255) NOT NULL,
                details           CLOB         DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_group_py FOREIGN KEY (programme_year_id) REFERENCES programme_year(id)
            )
        SQL);
        $this->addSql('INSERT INTO __group_old (id, name, details) SELECT id, name, details FROM "group"');
        $this->addSql('DROP TABLE "group"');
        $this->addSql('ALTER TABLE __group_old RENAME TO "group"');
        $this->addSql('CREATE INDEX IDX_group_py ON "group" (programme_year_id)');

        $this->addSql('DROP TABLE course');

        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
