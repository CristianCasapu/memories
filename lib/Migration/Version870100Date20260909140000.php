<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The album of a person remembers the photo order picked on the person:
 * "best photos first" and "only where they are in the foreground".
 */
final class Version870100Date20260909140000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('memories_person_albums')) {
            return null;
        }

        $table = $schema->getTable('memories_person_albums');

        if (!$table->hasColumn('sort_prominence')) {
            $table->addColumn('sort_prominence', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        }

        if (!$table->hasColumn('only_subjects')) {
            $table->addColumn('only_subjects', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        }

        return $schema;
    }
}
