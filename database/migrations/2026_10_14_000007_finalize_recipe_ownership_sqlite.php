<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Apply only after 000001–000006, with writes paused, a consistent SQLite
     * backup and a verified restore procedure. No backup is performed here.
     * Preflight reads schema plus aggregate/existence checks on application
     * rows only when explicitly executed. No row contents are logged.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || DB::getTablePrefix() !== '') {
            throw new RuntimeException('Ownership final memerlukan SQLite tanpa table prefix.');
        }
        if ((int) DB::scalar('PRAGMA foreign_keys') !== 1) {
            throw new RuntimeException('Foreign-key enforcement harus aktif sebelum migration.');
        }

        DB::transaction(function (): void {
            $definitions = $this->definitions();
            $sequences = [];
            // Validate BOTH tables before creating, copying or dropping anything.
            $this->assertNoDependencies(array_keys($definitions));
            foreach ($definitions as $table => $definition) {
                $this->preflight($table, $definition);
                $rows = DB::select('SELECT seq, typeof(seq) AS storage_type FROM sqlite_sequence WHERE name = ?', [$table]);
                if (count($rows) > 1 || (isset($rows[0]) && ($rows[0]->storage_type !== 'integer' || $rows[0]->seq < 0))) {
                    throw new RuntimeException('Metadata AUTOINCREMENT tidak dikenali.');
                }
                $sequences[$table] = isset($rows[0]) ? (int) $rows[0]->seq : 0;
            }

            foreach ($definitions as $table => $definition) {
                $temporary = '__ownership_'.$table;
                $columns = implode(', ', array_map($this->quote(...), array_keys($definition['columns'])));
                $newColumns = $definition['columns'];
                $newColumns['visitor_id'] = 'varchar';
                $sql = $this->tableSql($temporary, $newColumns);
                $sql = substr($sql, 0, -1).', CONSTRAINT '.$table.'_one_owner CHECK (
                    (visitor_id IS NOT NULL AND user_id IS NULL)
                    OR (visitor_id IS NULL AND user_id IS NOT NULL)
                ))';
                DB::statement($sql);
                DB::statement('INSERT INTO '.$this->quote($temporary).' ('.$columns.') SELECT '.$columns.' FROM '.$this->quote($table));

                $this->assertSameRows($table, $temporary, $columns);
                if (DB::select('PRAGMA foreign_key_check('.$this->quote($temporary).')') !== []) {
                    throw new RuntimeException('Foreign key hasil salinan tidak valid.');
                }

                // No incoming FK is allowed by preflight. Outgoing FK checks
                // remain enabled throughout; both rebuilds share this transaction.
                DB::statement('DROP TABLE '.$this->quote($table));
                DB::statement('ALTER TABLE '.$this->quote($temporary).' RENAME TO '.$this->quote($table));
                foreach ($definition['indexes'] as $name => [$unique, $indexColumns]) {
                    DB::statement($this->indexSql($table, $name, $unique, $indexColumns));
                }

                // Copying IDs alone loses a previously deleted high-water mark.
                $sequence = max($sequences[$table], (int) DB::scalar('SELECT COALESCE(MAX(id), 0) FROM '.$this->quote($table)));
                if (DB::select('SELECT name FROM sqlite_sequence WHERE name = ?', [$table]) === []) {
                    DB::insert('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, $sequence]);
                } else {
                    DB::update('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$sequence, $table]);
                }
                if ((int) DB::scalar('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]) !== $sequence
                    || DB::select('PRAGMA foreign_key_check('.$this->quote($table).')') !== []) {
                    throw new RuntimeException('Verifikasi akhir ownership gagal; transaksi dibatalkan.');
                }
            }
        });
    }

    private function definitions(): array
    {
        return [
            'saved_recipes' => [
                'columns' => [
                    'id' => 'integer primary key autoincrement not null',
                    'visitor_id' => 'varchar not null',
                    'recipe_title' => 'varchar not null',
                    'recipe_data' => 'text not null',
                    'recipe_fingerprint' => 'varchar not null',
                    'created_at' => 'datetime', 'updated_at' => 'datetime',
                    'user_id' => 'INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT',
                ],
                'indexes' => [
                    'saved_recipes_visitor_id_recipe_fingerprint_unique' => [true, ['visitor_id', 'recipe_fingerprint']],
                    'saved_recipes_visitor_id_created_at_index' => [false, ['visitor_id', 'created_at']],
                    'saved_recipes_user_created_index' => [false, ['user_id', 'created_at']],
                    'saved_recipes_user_fingerprint_unique' => [true, ['user_id', 'recipe_fingerprint']],
                ],
            ],
            'recipe_searches' => [
                'columns' => [
                    'id' => 'integer primary key autoincrement not null',
                    'visitor_id' => 'varchar not null',
                    'ingredients' => 'text not null', 'preferences' => 'text not null',
                    'recipe_count' => 'integer not null', 'snapshot_id' => 'varchar not null',
                    'created_at' => 'datetime', 'updated_at' => 'datetime',
                    'user_id' => 'INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT',
                    'recommendation_operation_id' => 'INTEGER NULL REFERENCES recommendation_operations(id) ON DELETE RESTRICT',
                ],
                'indexes' => [
                    'recipe_searches_visitor_id_id_index' => [false, ['visitor_id', 'id']],
                    'recipe_searches_user_created_index' => [false, ['user_id', 'created_at']],
                    'recipe_searches_operation_unique' => [true, ['recommendation_operation_id']],
                ],
            ],
        ];
    }

    private function preflight(string $table, array $definition): void
    {
        $sql = DB::scalar("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = ?", [$table]);
        // Full DDL comparison protects types, defaults, PK, FK, CHECK and table
        // options. Even equivalent but unfamiliar DDL must be reviewed manually.
        if (! is_string($sql) || $this->canonical($sql) !== $this->canonical($this->tableSql($table, $definition['columns']))) {
            throw new RuntimeException('Skema tabel tidak dikenali: '.$table);
        }
        $columns = DB::select('PRAGMA table_xinfo('.$this->quote($table).')');
        if (array_column($columns, 'name') !== array_keys($definition['columns'])) {
            throw new RuntimeException('Daftar kolom tidak sesuai: '.$table);
        }
        if (DB::select('SELECT name FROM sqlite_schema WHERE name = ?', ['__ownership_'.$table]) !== []) {
            throw new RuntimeException('Nama tabel rekonstruksi sudah digunakan.');
        }
        $indexes = DB::select('PRAGMA index_list('.$this->quote($table).')');
        if (count($indexes) !== count($definition['indexes'])) {
            throw new RuntimeException('Jumlah index tidak sesuai: '.$table);
        }
        foreach ($indexes as $index) {
            $expected = $definition['indexes'][$index->name] ?? null;
            $indexSql = DB::scalar("SELECT sql FROM sqlite_schema WHERE type = 'index' AND name = ?", [$index->name]);
            if ($expected === null || ! is_string($indexSql)
                || $this->canonical($indexSql) !== $this->canonical($this->indexSql($table, $index->name, ...$expected))) {
                throw new RuntimeException('Definisi index tidak dikenali: '.$table);
            }
        }
        if (DB::select('PRAGMA foreign_key_check('.$this->quote($table).')') !== []) {
            throw new RuntimeException('Foreign key existing tidak valid: '.$table);
        }
        if (DB::scalar('SELECT EXISTS(SELECT 1 FROM '.$this->quote($table).' WHERE NOT (
            (visitor_id IS NOT NULL AND user_id IS NULL)
            OR (visitor_id IS NULL AND user_id IS NOT NULL)
        ))')) {
            throw new RuntimeException('Ownership existing tidak valid; tidak ada data yang diperbaiki otomatis.');
        }
    }

    private function assertNoDependencies(array $targets): void
    {
        foreach (DB::select("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            foreach (DB::select('PRAGMA foreign_key_list('.$this->quote($table->name).')') as $foreign) {
                if (in_array(strtolower($foreign->table), $targets, true)) {
                    throw new RuntimeException('Ada foreign key masuk; rekonstruksi memerlukan review terpisah.');
                }
            }
        }
        foreach (DB::select("SELECT tbl_name, sql FROM sqlite_schema WHERE type IN ('trigger', 'view')") as $object) {
            foreach ($targets as $target) {
                if ($object->tbl_name === $target || stripos($object->sql ?? '', $target) !== false) {
                    throw new RuntimeException('Ada trigger/view terkait koleksi; pelestarian perlu review terpisah.');
                }
            }
        }
    }

    private function assertSameRows(string $original, string $copy, string $columns): void
    {
        $left = 'SELECT '.$columns.' FROM '.$this->quote($original);
        $right = 'SELECT '.$columns.' FROM '.$this->quote($copy);
        if (DB::scalar('SELECT COUNT(*) FROM '.$this->quote($original)) !== DB::scalar('SELECT COUNT(*) FROM '.$this->quote($copy))
            || DB::scalar('SELECT EXISTS('.$left.' EXCEPT '.$right.')')
            || DB::scalar('SELECT EXISTS('.$right.' EXCEPT '.$left.')')) {
            throw new RuntimeException('Salinan tidak identik; transaksi dibatalkan.');
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function tableSql(string $table, array $columns): string
    {
        $parts = [];
        foreach ($columns as $name => $type) {
            $parts[] = $this->quote($name).' '.$type;
        }

        return 'CREATE TABLE '.$this->quote($table).' ('.implode(', ', $parts).')';
    }

    private function indexSql(string $table, string $name, bool $unique, array $columns): string
    {
        return 'CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.$this->quote($name).' ON '.$this->quote($table)
            .' ('.implode(', ', array_map($this->quote(...), $columns)).')';
    }

    private function canonical(string $sql): string
    {
        // Expected DDL has no string literals. Do not normalize arbitrary DDL
        // into a match by stripping comments, constraints or expressions.
        return strtolower(preg_replace('/[\s"`]+/', '', trim($sql)));
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback otomatis ditolak: user-owned rows dapat memiliki visitor_id NULL. Pulihkan backup konsisten dengan prosedur terpisah yang telah diverifikasi.');
    }
};
