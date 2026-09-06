<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Person albums: albums that are kept in sync with the photos of a recognized person.
 */
final class Version810100Date20260906210000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if (!$schema->hasTable('memories_person_albums')) {
            $table = $schema->createTable('memories_person_albums');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('album_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('backend', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'recognize']);
            $table->addColumn('cluster_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('last_sync', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['album_id'], 'memories_pa_album');
            $table->addIndex(['uid', 'cluster_id'], 'memories_pa_cluster');
        }

        return $schema;
    }
}
