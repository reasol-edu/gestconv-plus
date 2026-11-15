<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\MigrationFactory;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pone el esquema de la base de datos en la versión de migración que se pida, para poder restaurar copias de seguridad
 * hechas con una versión anterior de la aplicación: se **borra todo y se migra desde cero** hasta la versión de la copia
 * (sin ejecutar ningún `down()`, que serían frágiles), se restauran los datos y se migra hasta la última, con lo que las
 * migraciones que transforman datos se aplican sobre los de la copia.
 *
 * Nada de esto es transaccional (el DDL de MySQL confirma solo): si falla, la base de datos queda a medias y solo sirve
 * volver a restaurar una copia. Por eso quien lo use tiene que haber guardado antes una copia de los datos actuales.
 *
 * Cada operación usa una fábrica de Doctrine Migrations nueva: sus objetos de migración se «congelan» al ejecutarse y no se
 * pueden volver a ejecutar, y su almacén de metadatos da por creada la tabla de versiones aunque se hayan borrado las tablas.
 */
class SchemaMigrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseTables $tables,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $configured,
    ) {}

    /** Si la migración existe entre las que trae esta versión de la aplicación (una copia de una versión posterior no). */
    public function isKnown(string $version): bool
    {
        return $this->factory()->getMigrationPlanCalculator()->getMigrations()->hasMigration(new Version($version));
    }

    /** La última migración que trae esta versión de la aplicación. */
    public function latest(): string
    {
        return (string) $this->factory()->getMigrationPlanCalculator()->getMigrations()->getLast()->getVersion();
    }

    /** Borra todas las tablas y migra desde cero hasta `$version` (incluida). */
    public function rebuildAt(string $version): void
    {
        $this->dropEverything();
        $this->migrateTo($version);
    }

    /** Aplica las migraciones pendientes hasta la última. */
    public function migrateToLatest(): void
    {
        $this->migrateTo($this->latest());
    }

    private function migrateTo(string $version): void
    {
        $factory = $this->factory();
        $factory->getMetadataStorage()->ensureInitialized();

        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        if ($plan->count() === 0) {
            return;
        }

        try {
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(false));
        } finally {
            // En MySQL el DDL confirma la transacción por su cuenta y Doctrine no llega a cerrar la que abrió para cada
            // migración: el contador de transacciones de la conexión se queda alto, y la siguiente transacción (la de la
            // restauración) usaría puntos de guardado que no existen. Cerrar la conexión lo pone a cero (se reabre sola).
            if ($this->tables->platform() === 'mysql' && $this->connection->getTransactionNestingLevel() > 0) {
                $this->connection->close();
            }
        }
    }

    /** Una fábrica nueva con la misma configuración (carpeta de migraciones, tabla de versiones) y la misma conexión. */
    private function factory(): DependencyFactory
    {
        $factory = DependencyFactory::fromConnection(
            new ExistingConfiguration($this->configured->getConfiguration()),
            new ExistingConnection($this->connection),
        );
        // La del bundle crea las migraciones con sus dependencias del contenedor (si alguna las pidiera).
        $factory->setService(MigrationFactory::class, $this->configured->getMigrationFactory());

        return $factory;
    }

    private function dropEverything(): void
    {
        $kind  = $this->tables->platform();
        $names = array_values(array_filter(
            array_map(static fn (string $name): string => trim($name, '"`'), $this->connection->createSchemaManager()->listTableNames()),
            static fn (string $name): bool => !str_starts_with($name, 'sqlite_'),
        ));

        // Con las claves foráneas activas habría que borrar en orden de dependencias (y hay ciclos).
        if ($kind === 'mysql') {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($kind === 'sqlite') {
            $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($names as $name) {
                $this->connection->executeStatement(sprintf('DROP TABLE %s%s', $this->connection->quoteSingleIdentifier($name), $kind === 'postgresql' ? ' CASCADE' : ''));
            }
        } finally {
            if ($kind === 'mysql') {
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($kind === 'sqlite') {
                $this->connection->executeStatement('PRAGMA foreign_keys = ON');
            }
        }
    }
}
