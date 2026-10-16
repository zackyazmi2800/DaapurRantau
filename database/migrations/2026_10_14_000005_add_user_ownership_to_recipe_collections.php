<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Migration ini hanya mendukung SQLite dan MySQL.');
        }
        // SQLite supports nullable REFERENCES columns via ADD COLUMN directly.
        // Avoid Blueprint operations that reconstruct existing tables.
        foreach (['saved_recipes', 'recipe_searches'] as $table) {
            if ($driver === 'sqlite') {
                DB::statement("ALTER TABLE {$table} ADD COLUMN user_id INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT");
            } else {
                DB::statement("ALTER TABLE {$table} ADD COLUMN user_id BIGINT UNSIGNED NULL, ADD CONSTRAINT {$table}_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT");
            }
            DB::statement("CREATE INDEX {$table}_user_created_index ON {$table} (user_id, created_at)");
        }
        if ($driver === 'sqlite') {
            DB::statement('ALTER TABLE recipe_searches ADD COLUMN recommendation_operation_id INTEGER NULL REFERENCES recommendation_operations(id) ON DELETE RESTRICT');
        } else {
            DB::statement('ALTER TABLE recipe_searches ADD COLUMN recommendation_operation_id BIGINT UNSIGNED NULL, ADD CONSTRAINT recipe_searches_operation_fk FOREIGN KEY (recommendation_operation_id) REFERENCES recommendation_operations(id) ON DELETE RESTRICT');
        }
        // Existing rows receive NULL: both SQLite and MySQL allow multiple NULLs
        // in these unique indexes. Existing visitor indexes and data stay intact.
        DB::statement('CREATE UNIQUE INDEX saved_recipes_user_fingerprint_unique ON saved_recipes (user_id, recipe_fingerprint)');
        DB::statement('CREATE UNIQUE INDEX recipe_searches_operation_unique ON recipe_searches (recommendation_operation_id)');
        // visitor_id remains NOT NULL. Relaxing it requires a separate reviewed
        // migration before writing user-only rows; do not synthesize visitor IDs.
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback ownership memerlukan rencana terpisah agar data lama tidak hilang.');
    }
};
