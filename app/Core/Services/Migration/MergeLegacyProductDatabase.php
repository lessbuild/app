<?php

namespace App\Core\Services\Migration;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use SQLite3;
use Throwable;

/** Merge a frozen product database snapshot into that product's live database. */
final class MergeLegacyProductDatabase
{
    private const PRODUCTS = [
        'deployer' => 'organizations',
        'monitor' => 'workspaces',
        'analytics' => 'workspaces',
    ];

    /** @var list<string> */
    private const DEPLOYER_PROVIDER_COLUMNS = [
        'github_id',
        'gitlab_id',
        'bitbucket_id',
    ];

    /** @var list<string> */
    private const EXCLUDED_TABLES = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'sessions',
    ];

    /** @var list<string> */
    private const QUEUE_TABLES = [
        'failed_jobs',
        'job_batches',
        'jobs',
    ];

    /** @var array<string, array<string, string>> */
    private const DEPLOYER_MORPH_TYPES = [
        'events' => [
            'parentable_type' => [
                'App\\Models\\Build' => 'App\\Modules\\Deployer\\Models\\Build',
                'App\\Models\\Provider' => 'App\\Modules\\Deployer\\Models\\Provider',
                'App\\Models\\Recipe' => 'App\\Modules\\Deployer\\Models\\Recipe',
                'App\\Models\\Server' => 'App\\Modules\\Deployer\\Models\\Server',
                'App\\Models\\ServerCommandExecution' => 'App\\Modules\\Deployer\\Models\\ServerCommandExecution',
                'App\\Models\\User' => 'App\\Modules\\Deployer\\Models\\User',
                'App\\Models\\Website' => 'App\\Modules\\Deployer\\Models\\Website',
            ],
        ],
        'logs' => [
            'parentable_type' => [
                'App\\Models\\Build' => 'App\\Modules\\Deployer\\Models\\Build',
                'App\\Models\\Website' => 'App\\Modules\\Deployer\\Models\\Website',
            ],
        ],
        'notifications' => [
            'notifiable_type' => [
                'App\\Models\\User' => 'App\\Modules\\Deployer\\Models\\User',
            ],
            'type' => [
                'App\\Notifications\\AccountSecurityNotification' => 'App\\Modules\\Deployer\\Notifications\\AccountSecurityNotification',
                'App\\Notifications\\FailureNotification' => 'App\\Modules\\Deployer\\Notifications\\FailureNotification',
            ],
        ],
    ];

    /**
     * @return array{product:string,applied:bool,source_rows:int,rows_imported:int,rows_preserved:int,excluded_rows:int,bootstrap_rows_relocated:int,morph_values_rewritten:int,account_provider_ids_restored:int,backup_path:?string}
     */
    public function run(string $product, string $sourceDatabase, bool $apply = false): array
    {
        if (! isset(self::PRODUCTS[$product])) {
            throw new RuntimeException('Choose one of the Deployer, Monitor, or Analytics databases.');
        }

        $sourcePath = realpath($sourceDatabase);
        $targetPath = realpath((string) config("database.connections.{$product}.database"));

        if ($sourcePath === false || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('The source database file is unavailable or unreadable.');
        }

        if ($targetPath === false || ! is_file($targetPath)) {
            throw new RuntimeException('The target product database must be an existing SQLite file.');
        }

        if ($sourcePath === $targetPath) {
            throw new RuntimeException('The source snapshot and target database must be different files.');
        }

        $connection = DB::connection($product);

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Product snapshot merging currently requires SQLite source and target databases.');
        }

        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        $source->busyTimeout(5000);

        try {
            $this->assertDatabaseHealthy($source, $connection->getPdo());
            $this->assertQueueTablesDrained($source);
            $this->assertCompatibleSchemas($source, $connection->getPdo());

            if (! $apply) {
                return $this->previewOnIsolatedCopy($product, $source, $targetPath);
            }

            $backupPath = $this->backupTarget($targetPath, $product);
            $result = $connection->transaction(function () use ($connection, $product, $source): array {
                $pdo = $connection->getPdo();
                $pdo->exec('PRAGMA defer_foreign_keys = ON');

                return $this->merge($product, $source, $pdo);
            }, attempts: 1);

            return [
                'product' => $product,
                'applied' => true,
                ...$result,
                'backup_path' => $backupPath,
            ];
        } finally {
            $source->close();
        }
    }

    /** @return array{product:string,applied:bool,source_rows:int,rows_imported:int,rows_preserved:int,excluded_rows:int,bootstrap_rows_relocated:int,morph_values_rewritten:int,account_provider_ids_restored:int,backup_path:null} */
    private function previewOnIsolatedCopy(string $product, SQLite3 $source, string $targetPath): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'buildpusher-product-merge-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a private temporary database for the preview.');
        }

        chmod($temporaryPath, 0600);
        $targetCopy = null;
        $pdo = null;

        try {
            $targetCopy = new SQLite3($temporaryPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $active = new SQLite3($targetPath, SQLITE3_OPEN_READONLY);
            $active->busyTimeout(5000);

            try {
                if (! $active->backup($targetCopy)) {
                    throw new RuntimeException('Could not make an isolated target copy for the preview.');
                }
            } finally {
                $active->close();
            }

            $targetCopy->close();
            $targetCopy = null;
            $pdo = new PDO('sqlite:'.$temporaryPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA foreign_keys = ON');

            if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
                throw new RuntimeException('SQLite foreign-key enforcement is required for the merge preview.');
            }

            $pdo->beginTransaction();
            $pdo->exec('PRAGMA defer_foreign_keys = ON');
            $result = $this->merge($product, $source, $pdo);
            $pdo->rollBack();

            return [
                'product' => $product,
                'applied' => false,
                ...$result,
                'backup_path' => null,
            ];
        } catch (Throwable $exception) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } finally {
            $pdo = null;
            if ($targetCopy instanceof SQLite3) {
                $targetCopy->close();
            }
            unlink($temporaryPath);
        }
    }

    /** @return array{source_rows:int,rows_imported:int,rows_preserved:int,excluded_rows:int,bootstrap_rows_relocated:int,morph_values_rewritten:int,account_provider_ids_restored:int} */
    private function merge(string $product, SQLite3 $source, PDO $target): array
    {
        $this->assertNoForeignKeyViolations($target);
        $sourceTables = $this->tableNames($source);
        $targetTables = $this->tableNames($target);
        $sourceRows = 0;
        $rowsImported = 0;
        $rowsPreserved = 0;
        $accountProviderIdsRestored = 0;
        $excludedRows = 0;

        foreach ($sourceTables as $table) {
            $count = $this->rowCount($source, $table);

            if (in_array($table, self::EXCLUDED_TABLES, true)) {
                $excludedRows += $count;

                continue;
            }

            $sourceRows += $count;
        }

        $bootstrapRowsRelocated = $this->relocateConflictingBootstrapRows(
            $product,
            self::PRODUCTS[$product],
            $source,
            $target,
            $sourceTables,
            $targetTables,
        );

        foreach ($sourceTables as $table) {
            if (in_array($table, self::EXCLUDED_TABLES, true)) {
                continue;
            }

            $sourceInfo = $this->tableInfo($source, $table);
            $sourceColumns = array_column($sourceInfo, 'name');
            $primaryKey = $this->primaryKeyColumns($sourceInfo);

            if ($primaryKey === []) {
                throw new RuntimeException("Source table [{$table}] has no primary key; review its migration before importing it.");
            }

            $targetStatement = $target->prepare(
                'INSERT OR IGNORE INTO '.$this->quote($table)
                .' ('.implode(', ', array_map($this->quote(...), $sourceColumns)).')'
                .' VALUES ('.implode(', ', array_fill(0, count($sourceColumns), '?')).')',
            );
            $sourceResult = $source->query(
                'SELECT '.implode(', ', array_map($this->quote(...), $sourceColumns)).' FROM '.$this->quote($table),
            );

            if ($sourceResult === false) {
                throw new RuntimeException("Could not read source table [{$table}].");
            }

            while ($sourceRow = $sourceResult->fetchArray(SQLITE3_ASSOC)) {
                $existing = $this->findTargetRow($target, $table, $primaryKey, $sourceRow);

                if ($existing !== null) {
                    if (! $this->canPreserveExistingRow($product, $table, $sourceRow, $existing, $sourceColumns)) {
                        throw new RuntimeException("Table [{$table}] contains conflicting primary keys; reconcile that table before importing.");
                    }

                    $accountProviderIdsRestored += $this->restoreDeployerProviderIds(
                        $product,
                        $table,
                        $sourceRow,
                        $existing,
                        $sourceColumns,
                        array_column($this->tableInfo($target, $table), 'name'),
                        $target,
                    );
                    $rowsPreserved++;

                    continue;
                }

                $this->bindAndExecute($targetStatement, array_values($sourceRow), $sourceInfo);

                if ($targetStatement->rowCount() === 1) {
                    $rowsImported++;

                    continue;
                }

                $existingAfterInsert = $this->findTargetRow($target, $table, $primaryKey, $sourceRow);

                if ($existingAfterInsert === null
                    || ! $this->canPreserveExistingRow($product, $table, $sourceRow, $existingAfterInsert, $sourceColumns)) {
                    throw new RuntimeException("Table [{$table}] has a conflicting unique value; reconcile it before importing.");
                }

                $rowsPreserved++;
            }
        }

        $morphValuesRewritten = $this->rewriteLegacyMorphTypes($product, $target, $targetTables);
        $this->updateAutoIncrementSequences($target, $targetTables);
        $this->assertNoForeignKeyViolations($target);
        $this->assertIntegrity($target);

        return [
            'source_rows' => $sourceRows,
            'rows_imported' => $rowsImported,
            'rows_preserved' => $rowsPreserved,
            'excluded_rows' => $excludedRows,
            'bootstrap_rows_relocated' => $bootstrapRowsRelocated,
            'morph_values_rewritten' => $morphValuesRewritten,
            'account_provider_ids_restored' => $accountProviderIdsRestored,
        ];
    }

    private function restoreDeployerProviderIds(
        string $product,
        string $table,
        array $source,
        array $existing,
        array $sourceColumns,
        array $targetColumns,
        PDO $target,
    ): int {
        if ($product !== 'deployer' || $table !== 'users'
            || $this->normalizeEmail($source['email'] ?? null) === null
            || $this->normalizeEmail($source['email'] ?? null) !== $this->normalizeEmail($existing['email'] ?? null)) {
            return 0;
        }

        $updates = [];
        foreach (self::DEPLOYER_PROVIDER_COLUMNS as $column) {
            if (! in_array($column, $sourceColumns, true) || ! in_array($column, $targetColumns, true)) {
                continue;
            }

            $sourceValue = trim((string) ($source[$column] ?? ''));
            if ($sourceValue === '') {
                continue;
            }

            $currentValue = trim((string) ($existing[$column] ?? ''));
            if ($currentValue === $sourceValue) {
                continue;
            }

            if ($currentValue !== '') {
                throw new RuntimeException('A matching Deployer account has conflicting provider identities; review it before importing.');
            }

            $conflict = $target->prepare(
                'SELECT 1 FROM "users" WHERE '.$this->quote($column).' = :provider_id AND "id" <> :user_id LIMIT 1',
            );
            $conflict->execute(['provider_id' => $sourceValue, 'user_id' => $existing['id']]);

            if ($conflict->fetchColumn() !== false) {
                throw new RuntimeException('A Deployer provider identity is already assigned to another account; review it before importing.');
            }

            $updates[$column] = $sourceValue;
        }

        if ($updates === []) {
            return 0;
        }

        $assignments = implode(', ', array_map(fn (string $column): string => $this->quote($column).' = :'.$column, array_keys($updates)));
        $statement = $target->prepare('UPDATE "users" SET '.$assignments.' WHERE "id" = :user_id');
        $statement->execute([...$updates, 'user_id' => $existing['id']]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Could not restore missing provider identities on a matching Deployer account.');
        }

        return count($updates);
    }

    private function relocateConflictingBootstrapRows(
        string $product,
        string $table,
        SQLite3 $source,
        PDO $target,
        array $sourceTables,
        array $targetTables,
    ): int {
        if (! in_array($table, $sourceTables, true) || ! in_array($table, $targetTables, true)) {
            return 0;
        }

        $sourceInfo = $this->tableInfo($source, $table);
        $targetInfo = $this->tableInfo($target, $table);

        if ($this->primaryKeyColumns($sourceInfo) !== ['id'] || $this->primaryKeyColumns($targetInfo) !== ['id']) {
            throw new RuntimeException("The [{$table}] table must retain its single-column ID during migration.");
        }

        $sourceColumns = array_column($sourceInfo, 'name');
        $sourceRows = $source->query('SELECT * FROM '.$this->quote($table));
        $targetRows = $target->query('SELECT * FROM '.$this->quote($table));
        $sourceById = [];
        $targetById = [];

        while ($row = $sourceRows->fetchArray(SQLITE3_ASSOC)) {
            $sourceById[(string) $row['id']] = $row;
        }

        while ($row = $targetRows->fetch(PDO::FETCH_ASSOC)) {
            $targetById[(string) $row['id']] = $row;
        }

        $numericIds = array_filter(
            array_merge(array_keys($sourceById), array_keys($targetById)),
            static fn (string $id): bool => ctype_digit($id),
        );

        if (count($numericIds) !== count(array_merge(array_keys($sourceById), array_keys($targetById)))) {
            throw new RuntimeException("The [{$table}] table contains non-numeric IDs that cannot be safely relocated.");
        }

        $nextId = $numericIds === [] ? 1 : max(array_map('intval', $numericIds)) + 1;
        $moved = 0;

        foreach (array_intersect(array_keys($sourceById), array_keys($targetById)) as $id) {
            $sourceRow = $sourceById[$id];
            $targetRow = $targetById[$id];

            if ($this->rowsMatch($sourceRow, $targetRow, $sourceColumns)) {
                continue;
            }

            $this->updateReferences($target, $table, 'id', $targetRow['id'], $nextId, $targetTables);
            $statement = $target->prepare('UPDATE '.$this->quote($table).' SET "id" = :new WHERE "id" = :old');
            $statement->execute(['new' => $nextId, 'old' => $targetRow['id']]);

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException("Could not relocate a conflicting bootstrap row from [{$table}].");
            }

            $nextId++;
            $moved++;
        }

        return $moved;
    }

    private function updateReferences(PDO $target, string $parentTable, string $parentColumn, int|string $oldId, int|string $newId, array $targetTables): void
    {
        $references = [];

        foreach ($targetTables as $table) {
            foreach ($this->pragmaRows($target, 'foreign_key_list', $table) as $foreignKey) {
                if ($foreignKey['table'] === $parentTable
                    && (($foreignKey['to'] ?? '') === $parentColumn || ($foreignKey['to'] ?? '') === '')) {
                    $references[$table][$foreignKey['from']] = true;
                }
            }

            $columns = array_column($this->tableInfo($target, $table), 'name');
            $semanticReferences = match ($parentTable) {
                'organizations' => ['organization_id', 'current_organization_id'],
                'workspaces' => ['workspace_id', 'current_workspace_id'],
                default => [],
            };

            foreach (array_intersect($semanticReferences, $columns) as $column) {
                $references[$table][$column] = true;
            }
        }

        foreach ($references as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $statement = $target->prepare(
                    'UPDATE '.$this->quote($table).' SET '.$this->quote($column).' = :new WHERE '.$this->quote($column).' = :old',
                );
                $statement->execute(['new' => $newId, 'old' => $oldId]);
            }
        }
    }

    private function canPreserveExistingRow(string $product, string $table, array $source, array $target, array $columns): bool
    {
        foreach (self::DEPLOYER_MORPH_TYPES[$table] ?? [] as $column => $types) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            if (isset($types[$source[$column] ?? ''])) {
                $source[$column] = $types[$source[$column]];
            }
        }

        if ($table === 'users') {
            $sourceEmail = $this->normalizeEmail($source['email'] ?? null);
            $targetEmail = $this->normalizeEmail($target['email'] ?? null);

            if ($sourceEmail !== null && $sourceEmail === $targetEmail) {
                return true;
            }

            $platformUserId = $target['platform_user_id'] ?? null;

            if (is_string($platformUserId) && $platformUserId !== '') {
                $mapping = DB::connection('core')->table('legacy_identity_maps')
                    ->where('source_product', $product)
                    ->where('source_entity', 'user')
                    ->where('source_id', (string) ($source['id'] ?? ''))
                    ->where('canonical_entity', 'user')
                    ->where('canonical_id', $platformUserId)
                    ->where('status', 'reconciled')
                    ->exists();

                if ($mapping) {
                    return true;
                }
            }
        }

        return $this->rowsMatch($source, $target, $columns);
    }

    private function rowsMatch(array $source, array $target, array $columns): bool
    {
        foreach ($columns as $column) {
            $sourceValue = $source[$column] ?? null;
            $targetValue = $target[$column] ?? null;

            if ($sourceValue === $targetValue) {
                continue;
            }

            if ($sourceValue !== null && $targetValue !== null && (string) $sourceValue === (string) $targetValue) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function rewriteLegacyMorphTypes(string $product, PDO $target, array $targetTables): int
    {
        if ($product !== 'deployer') {
            return 0;
        }

        $updated = 0;

        foreach (self::DEPLOYER_MORPH_TYPES as $table => $columns) {
            if (! in_array($table, $targetTables, true)) {
                continue;
            }

            $availableColumns = array_column($this->tableInfo($target, $table), 'name');

            foreach ($columns as $column => $types) {
                if (! in_array($column, $availableColumns, true)) {
                    continue;
                }

                foreach ($types as $legacyType => $moduleType) {
                    $statement = $target->prepare(
                        'UPDATE '.$this->quote($table).' SET '.$this->quote($column).' = :module WHERE '.$this->quote($column).' = :legacy',
                    );
                    $statement->execute(['module' => $moduleType, 'legacy' => $legacyType]);
                    $updated += $statement->rowCount();
                }
            }
        }

        return $updated;
    }

    private function updateAutoIncrementSequences(PDO $target, array $targetTables): void
    {
        $hasSequence = $target->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn();

        if (! $hasSequence) {
            return;
        }

        foreach ($targetTables as $table) {
            $columns = array_column($this->tableInfo($target, $table), 'name');

            if (! in_array('id', $columns, true)) {
                continue;
            }

            $max = $target->query('SELECT MAX(CAST("id" AS INTEGER)) FROM '.$this->quote($table))->fetchColumn();

            if ($max === false || $max === null) {
                continue;
            }

            $statement = $target->prepare('UPDATE sqlite_sequence SET seq = MAX(seq, :seq) WHERE name = :name');
            $statement->execute(['seq' => (int) $max, 'name' => $table]);

            if ($statement->rowCount() === 0) {
                $insert = $target->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)');
                $insert->execute(['name' => $table, 'seq' => (int) $max]);
            }
        }
    }

    private function bindAndExecute(\PDOStatement $statement, array $values, array $columnInfo): void
    {
        foreach (array_values($values) as $index => $value) {
            $declaredType = strtoupper((string) ($columnInfo[$index]['type'] ?? ''));
            $parameterType = match (true) {
                $value === null => PDO::PARAM_NULL,
                str_contains($declaredType, 'BLOB') => PDO::PARAM_LOB,
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($index + 1, $value, $parameterType);
        }

        $statement->execute();
    }

    private function findTargetRow(PDO $target, string $table, array $primaryKey, array $sourceRow): ?array
    {
        $where = implode(' AND ', array_map(fn (string $column): string => $this->quote($column).' = :'.$column, $primaryKey));
        $statement = $target->prepare('SELECT * FROM '.$this->quote($table).' WHERE '.$where.' LIMIT 1');

        foreach ($primaryKey as $column) {
            $statement->bindValue(':'.$column, $sourceRow[$column]);
        }

        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function assertDatabaseHealthy(SQLite3 $source, PDO $target): void
    {
        if ($source->querySingle('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('The source snapshot failed SQLite integrity_check.');
        }

        if ($target->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('The target database failed SQLite integrity_check.');
        }

        if ((int) $target->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new RuntimeException('SQLite foreign-key enforcement must be enabled on the target connection.');
        }
    }

    private function assertQueueTablesDrained(SQLite3 $source): void
    {
        $tables = $this->tableNames($source);

        foreach (self::QUEUE_TABLES as $table) {
            if (in_array($table, $tables, true) && $this->rowCount($source, $table) > 0) {
                throw new RuntimeException("Source queue table [{$table}] contains serialized jobs; drain or translate it before importing.");
            }
        }
    }

    private function assertCompatibleSchemas(SQLite3 $source, PDO $target): void
    {
        $sourceTables = $this->tableNames($source);
        $targetTables = $this->tableNames($target);

        foreach ($sourceTables as $table) {
            if (in_array($table, self::EXCLUDED_TABLES, true)) {
                continue;
            }

            if (! in_array($table, $targetTables, true)) {
                throw new RuntimeException("Source table [{$table}] has no table in the current product schema.");
            }

            $sourceColumns = array_column($this->tableInfo($source, $table), 'name');
            $targetColumns = array_column($this->tableInfo($target, $table), 'name');
            $sourceOnlyColumns = array_diff($sourceColumns, $targetColumns);

            if ($sourceOnlyColumns !== []) {
                throw new RuntimeException("Source table [{$table}] has columns missing from the current product schema.");
            }

            if ($this->primaryKeyColumns($this->tableInfo($source, $table)) === []) {
                throw new RuntimeException("Source table [{$table}] has no primary key; review its migration before importing.");
            }
        }
    }

    private function assertNoForeignKeyViolations(PDO $target): void
    {
        $violations = $target->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);

        if ($violations !== []) {
            throw new RuntimeException('The product database has foreign-key violations; resolve them before importing.');
        }
    }

    private function assertIntegrity(PDO $target): void
    {
        if ($target->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('The merged target database failed SQLite integrity_check.');
        }
    }

    private function backupTarget(string $targetPath, string $product): string
    {
        $backupRoot = (string) config('platform.migration_backup_directory', storage_path('app/private/platform-migration-backups'));

        if (! is_dir($backupRoot) && ! mkdir($backupRoot, 0700, true) && ! is_dir($backupRoot)) {
            throw new RuntimeException('Could not create the protected product-migration backup directory.');
        }

        $runDirectory = rtrim($backupRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .gmdate('Ymd\THis\Z').'-'.$product.'-'.bin2hex(random_bytes(4));

        if (! mkdir($runDirectory, 0700) && ! is_dir($runDirectory)) {
            throw new RuntimeException('Could not create a private target-backup directory.');
        }

        chmod($runDirectory, 0700);
        $backupPath = $runDirectory.DIRECTORY_SEPARATOR.'before.sqlite';
        $source = new SQLite3($targetPath, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

        try {
            $source->busyTimeout(5000);

            if (! $source->backup($destination)) {
                throw new RuntimeException('Could not make a consistent SQLite backup of the target product database.');
            }
        } finally {
            $source->close();
            $destination->close();
        }

        chmod($backupPath, 0600);

        return $backupPath;
    }

    private function tableNames(SQLite3|PDO $database): array
    {
        $rows = $database instanceof SQLite3
            ? $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            : $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        if ($rows === false) {
            throw new RuntimeException('Could not inspect SQLite table names.');
        }

        $tables = [];

        if ($database instanceof SQLite3) {
            while ($row = $rows->fetchArray(SQLITE3_NUM)) {
                $tables[] = (string) $row[0];
            }
        } else {
            $tables = array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN));
        }

        return $tables;
    }

    /** @return list<array{name:string,type:string,notnull:int,default:mixed,pk:int}> */
    private function tableInfo(SQLite3|PDO $database, string $table): array
    {
        $rows = $database->query('PRAGMA table_info('.$this->quote($table).')');

        if ($rows === false) {
            throw new RuntimeException("Could not inspect SQLite table [{$table}].");
        }

        $info = [];
        if ($database instanceof SQLite3) {
            while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                $info[] = [
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'notnull' => (int) $row['notnull'],
                    'default' => $row['dflt_value'],
                    'pk' => (int) $row['pk'],
                ];
            }
        } else {
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $info[] = [
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'notnull' => (int) $row['notnull'],
                    'default' => $row['dflt_value'],
                    'pk' => (int) $row['pk'],
                ];
            }
        }

        return $info;
    }

    /** @return list<array<string, mixed>> */
    private function pragmaRows(PDO $database, string $pragma, string $table): array
    {
        $rows = $database->query('PRAGMA '.$pragma.'('.$this->quote($table).')');

        return $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array{name:string,type:string,notnull:int,default:mixed,pk:int}> $info
     * @return list<string>
     */
    private function primaryKeyColumns(array $info): array
    {
        $primary = array_filter($info, static fn (array $column): bool => $column['pk'] > 0);
        usort($primary, static fn (array $left, array $right): int => $left['pk'] <=> $right['pk']);

        return array_values(array_map(static fn (array $column): string => $column['name'], $primary));
    }

    private function rowCount(SQLite3 $database, string $table): int
    {
        $count = $database->querySingle('SELECT COUNT(*) FROM '.$this->quote($table));

        if (! is_int($count)) {
            throw new RuntimeException("Could not count source rows in [{$table}].");
        }

        return $count;
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
