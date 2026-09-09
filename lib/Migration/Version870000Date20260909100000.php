<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Stories: a handful of photos shown one after the other, full screen, like the stories of a phone app. */
final class Version870000Date20260909100000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('memories_stories')) {
            $table = $schema->createTable('memories_stories');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('subtitle', Types::STRING, ['notnull' => false, 'length' => 255]);
            // where an automatic story came from ("thisday:2019", "event:12"); empty for a story
            // the person put together by hand. Keeps the same story from being made twice.
            $table->addColumn('auto_key', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $table->addColumn('cover', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            // the last photo the person watched, so a story that was left half way can be resumed
            $table->addColumn('seen', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uid', 'created'], 'memories_st_uid_time');
            // not unique: every story a person makes by hand carries an empty key
            $table->addIndex(['uid', 'auto_key'], 'memories_st_uid_key');
        }

        if (!$schema->hasTable('memories_stories_files')) {
            $table = $schema->createTable('memories_stories_files');
            $table->addColumn('story_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('fileid', Types::BIGINT, ['notnull' => true, 'length' => 20]);
            $table->addColumn('ordering', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['story_id', 'fileid']);
            $table->addIndex(['fileid'], 'memories_stf_file');
        }

        return $schema;
    }
}
