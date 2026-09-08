<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Clips: what a video says (caption, location, people, text lines) and where it came from. */
final class Version850000Date20260908160000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('memories_video_jobs');
        $changed = false;
        foreach ([
            ['caption', Types::STRING, ['notnull' => false, 'length' => 1024]],
            ['location', Types::STRING, ['notnull' => false, 'length' => 255]],
            ['mentions', Types::TEXT, ['notnull' => false]],
            ['texts', Types::TEXT, ['notnull' => false]],
            ['kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'manual']],
            ['source', Types::TEXT, ['notnull' => false]],
        ] as [$name, $type, $opts]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $opts);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
