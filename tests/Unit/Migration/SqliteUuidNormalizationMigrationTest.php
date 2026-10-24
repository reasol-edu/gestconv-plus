<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

require_once __DIR__ . '/../../../migrations/sqlite/Version20260101000049.php';

/**
 * La migración 049 repara los ids que antiguas migraciones sembraron en SQLite como texto de 36
 * caracteres o como blob, y que Doctrine no encuentra: Doctrine guarda el tipo uuid como un TEXTO con
 * los 16 bytes del UUID.
 */
final class SqliteUuidNormalizationMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('CREATE TABLE parent (id CHAR(36) NOT NULL, name VARCHAR(20) NOT NULL, PRIMARY KEY(id))');
        $this->connection->executeStatement('CREATE TABLE child (id CHAR(36) NOT NULL, parent_id CHAR(36) NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_child_parent FOREIGN KEY (parent_id) REFERENCES parent(id))');
        // Una tabla cuyo id no es un uuid no se toca.
        $this->connection->executeStatement('CREATE TABLE counter (id INTEGER PRIMARY KEY, label VARCHAR(20) NOT NULL)');
        $this->connection->executeStatement("INSERT INTO counter (id, label) VALUES (1, 'x')");
    }

    public function testIdsInTheWrongFormatsBecomeTheDoctrineFormatAndTheirReferencesFollow(): void
    {
        $asText = Uuid::fromString('00000000-0000-4000-8000-000000000001');
        $asBlob = Uuid::fromString('00000000-0000-4000-8000-000000000002');
        $right  = Uuid::fromString('00000000-0000-4000-8000-000000000003');

        // Formato de las semillas antiguas: texto de 36 caracteres, y blob.
        $this->connection->executeStatement('INSERT INTO parent (id, name) VALUES (?, ?)', [$asText->toRfc4122(), 'texto']);
        $this->connection->executeStatement(sprintf("INSERT INTO parent (id, name) VALUES (X'%s', 'blob')", bin2hex($asBlob->toBinary())));
        // Formato de Doctrine: los 16 bytes enviados como cadena.
        $this->connection->executeStatement('INSERT INTO parent (id, name) VALUES (?, ?)', [$right->toBinary(), 'bien']);

        $this->connection->executeStatement('INSERT INTO child (id, parent_id) VALUES (?, ?)', [Uuid::v7()->toBinary(), $asText->toRfc4122()]);
        $this->connection->executeStatement(sprintf("INSERT INTO child (id, parent_id) VALUES (?, X'%s')", bin2hex($asBlob->toBinary())), [Uuid::v7()->toBinary()]);
        $this->connection->executeStatement('INSERT INTO child (id, parent_id) VALUES (?, ?)', [Uuid::v7()->toBinary(), $right->toBinary()]);

        $this->migrate();

        foreach (['texto' => $asText, 'blob' => $asBlob, 'bien' => $right] as $name => $uuid) {
            // Lo que hace Doctrine al buscar: enviar los 16 bytes como cadena.
            self::assertSame($name, $this->connection->fetchOne('SELECT name FROM parent WHERE id = ?', [$uuid->toBinary()]), "el padre «{$name}» se encuentra con el id de Doctrine");
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM child WHERE parent_id = ?', [$uuid->toBinary()]), "el hijo del padre «{$name}» sigue apuntando a él");
        }
        self::assertSame([], $this->connection->fetchAllAssociative('PRAGMA foreign_key_check'));
        self::assertSame('x', $this->connection->fetchOne('SELECT label FROM counter WHERE id = 1'), 'una tabla sin ids uuid no se toca');
    }

    public function testADatabaseWithoutWrongIdsIsLeftAlone(): void
    {
        $right = Uuid::fromString('00000000-0000-4000-8000-000000000003');
        $this->connection->executeStatement('INSERT INTO parent (id, name) VALUES (?, ?)', [$right->toBinary(), 'bien']);

        $migration = $this->migrate();

        self::assertSame([], $migration->getSql(), 'no se genera ninguna sentencia');
        self::assertSame('bien', $this->connection->fetchOne('SELECT name FROM parent WHERE id = ?', [$right->toBinary()]));
    }

    private function migrate(): \DoctrineMigrations\Version20260101000049
    {
        $migration = new \DoctrineMigrations\Version20260101000049($this->connection, new NullLogger());
        $migration->up(new Schema());

        // Como el ejecutor de migraciones: dentro de una transacción, con las sentencias y sus parámetros.
        $this->connection->beginTransaction();
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->connection->commit();

        return $migration;
    }
}
