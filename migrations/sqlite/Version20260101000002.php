<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tutores y teléfonos de contacto a estudiante; amplía observaciones a texto largo (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // SQLite es de tipado dinámico: VARCHAR(255) ya admite texto de cualquier longitud,
        // por lo que no se necesita migrar la columna details.
        $this->addSql('ALTER TABLE student ADD COLUMN tutor_name1          VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN tutor_name2          VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN contact_phone1       VARCHAR(50)  DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN contact_phone1_notes CLOB         DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN contact_phone2       VARCHAR(50)  DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN contact_phone2_notes CLOB         DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN contact_phone3       VARCHAR(50)  DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN contact_phone3_notes CLOB         DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE student DROP COLUMN tutor_name1');
        $this->addSql('ALTER TABLE student DROP COLUMN tutor_name2');
        $this->addSql('ALTER TABLE student DROP COLUMN contact_phone1');
        $this->addSql('ALTER TABLE student DROP COLUMN contact_phone1_notes');
        $this->addSql('ALTER TABLE student DROP COLUMN contact_phone2');
        $this->addSql('ALTER TABLE student DROP COLUMN contact_phone2_notes');
        $this->addSql('ALTER TABLE student DROP COLUMN contact_phone3');
        $this->addSql('ALTER TABLE student DROP COLUMN contact_phone3_notes');
    }
}
