<?php
/**
 * EasyRide locked deployment migration runner.
 *
 * Usage: php scripts/maintenance/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bootstrap.php';
require_once $projectRoot . '/includes/migrations.php';

function cliMigrationLog(string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$message}" . PHP_EOL);
}

function cliSplitSqlStatements(string $sql): array {
    $statements = [];
    $current = '';
    $inSingleQuote = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        if ($char === "'" && !$inSingleQuote) {
            $inSingleQuote = true;
        } elseif ($char === "'" && $inSingleQuote) {
            if ($index + 1 < $length && $sql[$index + 1] === "'") {
                $current .= "''";
                $index++;
                continue;
            }
            $inSingleQuote = false;
        } elseif ($char === '\\' && $inSingleQuote && $index + 1 < $length) {
            $current .= $char . $sql[++$index];
            continue;
        }

        if ($char === ';' && !$inSingleQuote) {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }

    $statement = trim($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements;
}

function cliRunSqlMigrations(string $projectRoot): int {
    $directory = $projectRoot . '/sql/migrations';
    if (!is_dir($directory)) {
        return 0;
    }

    dbExecute(
        'CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (migration)
        )'
    );

    $applied = array_flip(array_column(dbFetchAll('SELECT migration FROM migrations'), 'migration'));
    $files = glob($directory . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    $executed = 0;

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }

        cliMigrationLog("Applying SQL migration: {$name}");
        $sql = (string) file_get_contents($file);
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = trim($line);
            if (preg_match('/^--\s*mkdir:\s*([A-Za-z0-9_./-]+)$/i', $trimmed, $match)) {
                $relativePath = trim($match[1], '/');
                if ($relativePath === '' || str_contains($relativePath, '..')) {
                    throw new RuntimeException("Unsafe mkdir directive in {$name}");
                }
                $targetDirectory = $projectRoot . '/' . $relativePath;
                if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
                    throw new RuntimeException("Could not create migration directory: {$relativePath}");
                }
                continue;
            }
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }

        foreach (cliSplitSqlStatements(implode("\n", $lines)) as $statement) {
            dbExecute($statement);
        }
        dbInsert('INSERT INTO migrations (migration) VALUES (?)', [$name]);
        $executed++;
    }

    return $executed;
}

$lockName = 'easyride:deployment:migrations';
$lockAcquired = false;

try {
    $lockAcquired = (int) dbFetchValue('SELECT GET_LOCK(?, 0)', [$lockName]) === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('Another migration process already holds the deployment lock.');
    }

    $before = (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
    cliMigrationLog('Schema version before: ' . $before . ' (target ' . DB_SCHEMA_VERSION . ')');

    $sqlExecuted = cliRunSqlMigrations($projectRoot);
    runSchemaMigrations();

    $after = (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
    if ($after < DB_SCHEMA_VERSION) {
        throw new RuntimeException("Schema migration did not reach target version: {$after}/" . DB_SCHEMA_VERSION);
    }

    cliMigrationLog("Completed successfully: {$sqlExecuted} SQL file(s), schema version {$after}.");
} catch (Throwable $exception) {
    fwrite(STDERR, '[migration] FAILED: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($lockAcquired) {
        dbFetchValue('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}

exit(isset($exitCode) ? $exitCode : 0);
