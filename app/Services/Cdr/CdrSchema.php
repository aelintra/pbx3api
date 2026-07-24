<?php

namespace App\Services\Cdr;

use PDO;

/**
 * Asterisk cdr_sqlite3_custom table shape (Phase 6 / velocity V1).
 */
final class CdrSchema
{
    /** CREATE TABLE matching packaged cdr_sqlite3_custom.conf columns. */
    public const CREATE_TABLE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS cdr (
    calldate TEXT,
    clid TEXT,
    src TEXT,
    dst TEXT,
    dcontext TEXT,
    channel TEXT,
    dstchannel TEXT,
    lastapp TEXT,
    lastdata TEXT,
    duration INTEGER,
    billsec INTEGER,
    disposition TEXT,
    amaflags INTEGER,
    accountcode TEXT,
    uniqueid TEXT,
    userfield TEXT,
    linkedid TEXT
)
SQL;

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(self::CREATE_TABLE_SQL);
    }
}
