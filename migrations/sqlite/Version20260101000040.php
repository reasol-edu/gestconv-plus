<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade file_id (referencia opcional a setting_file) en global_setting_value, centre_setting_value y teacher_setting_value (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE global_setting_value ADD COLUMN file_id CHAR(36) DEFAULT NULL REFERENCES setting_file (id)');
        $this->addSql('CREATE INDEX IDX_global_setting_value_file ON global_setting_value (file_id)');

        $this->addSql('ALTER TABLE centre_setting_value ADD COLUMN file_id CHAR(36) DEFAULT NULL REFERENCES setting_file (id)');
        $this->addSql('CREATE INDEX IDX_centre_setting_value_file ON centre_setting_value (file_id)');

        $this->addSql('ALTER TABLE teacher_setting_value ADD COLUMN file_id CHAR(36) DEFAULT NULL REFERENCES setting_file (id)');
        $this->addSql('CREATE INDEX IDX_teacher_setting_value_file ON teacher_setting_value (file_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP INDEX IDX_teacher_setting_value_file');
        $this->addSql('ALTER TABLE teacher_setting_value DROP COLUMN file_id');

        $this->addSql('DROP INDEX IDX_centre_setting_value_file');
        $this->addSql('ALTER TABLE centre_setting_value DROP COLUMN file_id');

        $this->addSql('DROP INDEX IDX_global_setting_value_file');
        $this->addSql('ALTER TABLE global_setting_value DROP COLUMN file_id');
    }
}
