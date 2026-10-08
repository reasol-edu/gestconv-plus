<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del tamaño máximo de los ficheros que se suben: por fichero y por envío (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'uploads.max_file_size_mb',  'integer', '10', TRUE, FALSE, FALSE, 1, 1024, 'settings.category.uploads', 90, 10),
                (gen_random_uuid(), 'uploads.max_total_size_mb', 'integer', '50', TRUE, FALSE, FALSE, 1, 2048, 'settings.category.uploads', 90, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('uploads.max_file_size_mb', 'uploads.max_total_size_mb')");
    }
}
