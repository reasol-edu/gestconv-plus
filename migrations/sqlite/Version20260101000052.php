<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste events.publish_scope: quién puede publicar eventos del centro, a nivel global y de centro (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // El id va como parámetro con los 16 bytes del UUID, que es como lo envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (?, 'events.publish_scope', 'choice', 'management', 1, 1, 0, NULL, NULL, 'all,management', 'settings.category.events', 35, 10)",
            [Uuid::fromString('9b2e6d14-5c7a-4e83-8f01-3a6d9c2b7e52')->toBinary()]
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'events.publish_scope')");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'events.publish_scope')");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'events.publish_scope'");
    }
}
