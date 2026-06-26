<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tutores y teléfonos de contacto a estudiante; amplía observaciones a texto largo (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                ALTER COLUMN details              TYPE TEXT,
                ADD COLUMN   tutor_name1          VARCHAR(255) DEFAULT NULL,
                ADD COLUMN   tutor_name2          VARCHAR(255) DEFAULT NULL,
                ADD COLUMN   contact_phone1       VARCHAR(50)  DEFAULT NULL,
                ADD COLUMN   contact_phone1_notes TEXT         DEFAULT NULL,
                ADD COLUMN   contact_phone2       VARCHAR(50)  DEFAULT NULL,
                ADD COLUMN   contact_phone2_notes TEXT         DEFAULT NULL,
                ADD COLUMN   contact_phone3       VARCHAR(50)  DEFAULT NULL,
                ADD COLUMN   contact_phone3_notes TEXT         DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                ALTER COLUMN details              TYPE VARCHAR(255),
                DROP COLUMN  tutor_name1,
                DROP COLUMN  tutor_name2,
                DROP COLUMN  contact_phone1,
                DROP COLUMN  contact_phone1_notes,
                DROP COLUMN  contact_phone2,
                DROP COLUMN  contact_phone2_notes,
                DROP COLUMN  contact_phone3,
                DROP COLUMN  contact_phone3_notes
        SQL);
    }
}
