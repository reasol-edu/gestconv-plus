<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del tamaño máximo de los ficheros que se suben: por fichero y por envío (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000039', 'uploads.max_file_size_mb',  'integer', '10', 1, 0, 0, 1, 1024, 'settings.category.uploads', 90, 10),
            ('00000000-0000-4000-8000-000000000040', 'uploads.max_total_size_mb', 'integer', '50', 1, 0, 0, 1, 2048, 'settings.category.uploads', 90, 20)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb')");
    }
}
