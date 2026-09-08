<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Videos made from photos are created in the background; this table is what the user and
 * the administrator see: the queue, the progress, the result and any error.
 */
final class Version840000Date20260908150000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('memories_video_jobs')) {
            return null;
        }
        $table = $schema->createTable('memories_video_jobs');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'queued']);
        $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        $table->addColumn('file_ids', Types::TEXT, ['notnull' => true]);
        $table->addColumn('photos', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('fps', Types::FLOAT, ['notnull' => true, 'default' => 2]);
        $table->addColumn('music', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'auto']);
        $table->addColumn('mood', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => '']);
        $table->addColumn('progress', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('step', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        $table->addColumn('result_fileid', Types::BIGINT, ['notnull' => false, 'length' => 20]);
        $table->addColumn('result_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        $table->addColumn('result_folder', Types::STRING, ['notnull' => true, 'length' => 1024, 'default' => '']);
        $table->addColumn('track', Types::TEXT, ['notnull' => false]);
        $table->addColumn('error', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('started', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('finished', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['id'], 'memories_vjobs_pk');
        $table->addIndex(['uid', 'created'], 'memories_vjobs_uid');
        $table->addIndex(['status'], 'memories_vjobs_status');

        return $schema;
    }
}
