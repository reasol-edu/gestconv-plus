<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade file_id (referencia opcional a setting_file) en global_setting_value, centre_setting_value y teacher_setting_value (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE global_setting_value ADD file_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_global_setting_value_file ON global_setting_value (file_id)');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_global_setting_value_file FOREIGN KEY (file_id) REFERENCES setting_file(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE centre_setting_value ADD file_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_centre_setting_value_file ON centre_setting_value (file_id)');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_centre_setting_value_file FOREIGN KEY (file_id) REFERENCES setting_file(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE teacher_setting_value ADD file_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_teacher_setting_value_file ON teacher_setting_value (file_id)');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_teacher_setting_value_file FOREIGN KEY (file_id) REFERENCES setting_file(id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE teacher_setting_value DROP CONSTRAINT FK_teacher_setting_value_file');
        $this->addSql('ALTER TABLE teacher_setting_value DROP COLUMN file_id');

        $this->addSql('ALTER TABLE centre_setting_value DROP CONSTRAINT FK_centre_setting_value_file');
        $this->addSql('ALTER TABLE centre_setting_value DROP COLUMN file_id');

        $this->addSql('ALTER TABLE global_setting_value DROP CONSTRAINT FK_global_setting_value_file');
        $this->addSql('ALTER TABLE global_setting_value DROP COLUMN file_id');
    }
}
