<?php
/**
 * Fodor Review OS — SQLite PDO Database Layer
 *
 * Provides a singleton PDO connection and lightweight query helpers.
 * The database file is created automatically from schema.sql on first run.
 */

require_once __DIR__ . '/config.php';

// ----------------------------------------------------------------
// Singleton PDO connection
// ----------------------------------------------------------------

/**
 * Returns the singleton SQLite PDO instance.
 * On first call, creates the database file and applies schema.sql
 * if the DB file does not yet exist.
 *
 * @return PDO
 * @throws RuntimeException when the DB cannot be created or schema cannot be applied
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dbPath    = DB_PATH;
    $dbDir     = dirname($dbPath);
    $isNewDb   = !file_exists($dbPath);

    // Ensure the data directory exists
    if (!is_dir($dbDir)) {
        if (!mkdir($dbDir, 0750, true) && !is_dir($dbDir)) {
            throw new RuntimeException("Cannot create database directory: {$dbDir}");
        }
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);

        // Enable WAL mode for better concurrency
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');  // 5 s wait on lock

    } catch (PDOException $e) {
        throw new RuntimeException('Cannot open SQLite database: ' . $e->getMessage(), 0, $e);
    }

    // Bootstrap schema + seed data on first run
    if ($isNewDb) {
        $schemaPath = dirname(__DIR__) . '/data/schema.sql';
        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema file not found: {$schemaPath}");
        }

        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new RuntimeException("Cannot read schema file: {$schemaPath}");
        }

        try {
            // SQLite does not support multi-statement exec reliably for every driver;
            // split on statement boundary and run each individually.
            // We use a simple splitter: split on ';' followed by newline.
            $statements = preg_split(
                '/;\s*[\r\n]+/',
                $sql,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            foreach ($statements as $stmt) {
                // Strip leading comment lines (-- ...) before checking emptiness.
                // Each split chunk may begin with comment lines followed by the
                // actual SQL statement — we must not discard the whole chunk.
                $stmt = preg_replace('/^(--[^\r\n]*[\r\n]*)+/m', '', $stmt);
                $stmt = trim($stmt);
                if ($stmt === '') {
                    continue;
                }
                $pdo->exec($stmt);
            }

            log_event('info', 'Database created and schema applied', ['db_path' => $dbPath]);
        } catch (PDOException $e) {
            // Remove the broken DB file so next request retries cleanly
            @unlink($dbPath);
            throw new RuntimeException('Schema apply failed: ' . $e->getMessage(), 0, $e);
        }
    }

    return $pdo;
}


// ----------------------------------------------------------------
// Query helpers
// ----------------------------------------------------------------

/**
 * Execute a SELECT and return all rows as an associative array.
 *
 * @param string  $sql
 * @param array   $params  Named (:name) or positional (?) placeholders
 * @return array
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}


/**
 * Execute a SELECT and return the first row, or false if no rows.
 *
 * @param string  $sql
 * @param array   $params
 * @return array|false
 */
function db_fetch_one(string $sql, array $params = []): array|false
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row !== false ? $row : false;
}


/**
 * Insert a row into $table and return the last insert ID.
 *
 * @param string  $table   Table name (NOT user-supplied — must be a literal string)
 * @param array   $data    Column => value pairs
 * @return int    Last insert ID
 */
function db_insert(string $table, array $data): int
{
    if (empty($data)) {
        throw new InvalidArgumentException('db_insert: data array must not be empty');
    }

    // Build column list and named placeholder list
    $columns      = array_keys($data);
    $placeholders = array_map(fn($col) => ':' . $col, $columns);

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columns),
        implode(', ', $placeholders)
    );

    $namedParams = [];
    foreach ($data as $col => $val) {
        $namedParams[':' . $col] = $val;
    }

    db()->prepare($sql)->execute($namedParams);
    return (int) db()->lastInsertId();
}


/**
 * Update rows in $table that match $where and return the number of affected rows.
 *
 * Example:
 *   db_update('agents', ['status' => 'inactive'], 'id = :id', [':id' => 5]);
 *
 * @param string  $table
 * @param array   $data         Column => value pairs to SET
 * @param string  $where        WHERE clause (use named placeholders, e.g. "id = :id")
 * @param array   $where_params Placeholder => value pairs for the WHERE clause
 * @return int    Number of affected rows
 */
function db_update(string $table, array $data, string $where, array $where_params = []): int
{
    if (empty($data)) {
        throw new InvalidArgumentException('db_update: data array must not be empty');
    }

    $setParts    = [];
    $namedParams = [];

    foreach ($data as $col => $val) {
        $placeholder          = ':set_' . $col;  // prefix to avoid collision with WHERE params
        $setParts[]           = $col . ' = ' . $placeholder;
        $namedParams[$placeholder] = $val;
    }

    $sql = sprintf(
        'UPDATE %s SET %s WHERE %s',
        $table,
        implode(', ', $setParts),
        $where
    );

    $allParams = array_merge($namedParams, $where_params);

    $stmt = db()->prepare($sql);
    $stmt->execute($allParams);
    return $stmt->rowCount();
}


/**
 * Execute any SQL statement (DELETE, UPDATE, raw DDL) and return affected row count.
 *
 * @param string  $sql
 * @param array   $params
 * @return int    Number of affected rows
 */
function db_run(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}


// ----------------------------------------------------------------
// Logging
// ----------------------------------------------------------------

/**
 * Write a timestamped JSON log line to LOG_PATH.
 *
 * Log levels (ascending severity): debug < info < error
 * A message is written only when its level is >= the configured LOG_LEVEL.
 *
 * @param string  $level    'debug' | 'info' | 'error'
 * @param string  $message
 * @param array   $context  Optional extra data — will be JSON-encoded
 */
function log_event(string $level, string $message, array $context = []): void
{
    $levels = ['debug' => 0, 'info' => 1, 'error' => 2];

    $configuredLevel = defined('LOG_LEVEL') ? LOG_LEVEL : 'info';
    $minSeverity     = $levels[$configuredLevel] ?? 1;
    $msgSeverity     = $levels[$level]           ?? 1;

    if ($msgSeverity < $minSeverity) {
        return;  // below configured threshold — skip
    }

    $logPath = defined('LOG_PATH') ? LOG_PATH : dirname(__DIR__) . '/data/app.log';
    $logDir  = dirname($logPath);

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $entry = json_encode([
        'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
        'level'   => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    @file_put_contents($logPath, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}
