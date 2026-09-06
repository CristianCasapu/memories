<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Automatic events: photos grouped by shooting session (time gaps / distance).
 */
final class Version810101Date20260907090000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        if (!$schema->hasTable('memories_events')) {
            $table = $schema->createTable('memories_events');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('start', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('end', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('place', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('cover', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('updated', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uid', 'start'], 'memories_ev_uid_start');
        }

        if (!$schema->hasTable('memories_events_files')) {
            $table = $schema->createTable('memories_events_files');
            $table->addColumn('event_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('fileid', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->setPrimaryKey(['event_id', 'fileid']);
            $table->addIndex(['fileid'], 'memories_evf_file');
        }

        return $schema;
    }
}
