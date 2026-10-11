<?php
/* ────────────────────────────────────────────────────────────────
   Brings an older database up to date by itself.

   The alternative — "remember to paste this SQL into phpMyAdmin" — is a step
   that gets forgotten, and when it is forgotten the failure looks like a bug
   in the app rather than a missing column. On a single offline machine there
   is no reason to make a human do it.

   It runs once. A marker file in storage/ means later requests skip it
   entirely, so the 60 PCs polling every second never touch this.

   It never deletes anything. New columns are added as NULL-able, so rows
   registered before this change survive with an empty email.
   ──────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/../db.php';

const SCHEMA_MARK = '.schema-2-email-phone';

function table_columns($pdo, $table) {
    $names = [];
    try {
        $st = $pdo->query("SELECT * FROM $table LIMIT 0");
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $m = $st->getColumnMeta($i);
            if (isset($m['name'])) $names[] = strtolower($m['name']);
        }
    } catch (Exception $e) { /* table missing — the schema import has not run */ }
    return $names;
}

/* Each step is allowed to fail: the index may already be gone, or already
   exist. What matters is the state afterwards, not whether every statement
   had something to do. */
function try_exec($pdo, $sql) {
    try { $pdo->exec($sql); return true; } catch (Exception $e) { return false; }
}

function ensure_schema() {
    $dir  = defined('STORAGE') ? STORAGE : __DIR__ . '/../storage';
    $mark = $dir . '/' . SCHEMA_MARK;
    if (is_file($mark)) return;              // already done, and this is the usual path

    $pdo  = db();
    $cols = table_columns($pdo, 'participants');
    if (!$cols) return;                      // no tables yet; schema.sql has not been imported

    $isSqlite = strpos(strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)), 'sqlite') !== false;

    if (!in_array('email', $cols, true)) {
        try_exec($pdo, 'ALTER TABLE participants ADD COLUMN email VARCHAR(120) NULL');
    }
    if (!in_array('phone', $cols, true)) {
        try_exec($pdo, 'ALTER TABLE participants ADD COLUMN phone VARCHAR(20) NULL');
    }

    /* The name is no longer the identity, so it must stop being unique —
       otherwise the second Meet Patel cannot register. */
    try_exec($pdo, $isSqlite ? 'DROP INDEX IF EXISTS uq_name'
                             : 'ALTER TABLE participants DROP INDEX uq_name');

    /* The email is the identity now. NULL is allowed more than once in both
       MySQL and SQLite, so older rows without one do not collide. */
    try_exec($pdo, 'CREATE UNIQUE INDEX uq_email ON participants (email)');

    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($mark, date('c') . " schema brought up to date\n");
}
