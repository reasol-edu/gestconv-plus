<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tutores y teléfonos de contacto a estudiante; amplía observaciones a texto largo (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                MODIFY details               LONGTEXT     DEFAULT NULL,
                ADD    tutor_name1           VARCHAR(255) DEFAULT NULL,
                ADD    tutor_name2           VARCHAR(255) DEFAULT NULL,
                ADD    contact_phone1        VARCHAR(50)  DEFAULT NULL,
                ADD    contact_phone1_notes  LONGTEXT     DEFAULT NULL,
                ADD    contact_phone2        VARCHAR(50)  DEFAULT NULL,
                ADD    contact_phone2_notes  LONGTEXT     DEFAULT NULL,
                ADD    contact_phone3        VARCHAR(50)  DEFAULT NULL,
                ADD    contact_phone3_notes  LONGTEXT     DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                MODIFY details               VARCHAR(255) DEFAULT NULL,
                DROP COLUMN tutor_name1,
                DROP COLUMN tutor_name2,
                DROP COLUMN contact_phone1,
                DROP COLUMN contact_phone1_notes,
                DROP COLUMN contact_phone2,
                DROP COLUMN contact_phone2_notes,
                DROP COLUMN contact_phone3,
                DROP COLUMN contact_phone3_notes
        SQL);
    }
}
