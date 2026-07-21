<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair Sprint 24 migration when 2026_07_05_010000 failed on MySQL index name length (1059).
 * Safe if the original migration completed after the index-name fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_comments') && ! $this->indexExists('collaboration_comments', 'collab_comments_entity_idx')) {
            Schema::table('collaboration_comments', function (Blueprint $table): void {
                $table->index(['project_id', 'entity_type', 'entity_uuid'], 'collab_comments_entity_idx');
            });
        }

        if (Schema::hasTable('collaboration_participants') && ! $this->indexExists('collaboration_participants', 'collab_part_entity_idx')) {
            Schema::table('collaboration_participants', function (Blueprint $table): void {
                $table->index(['project_id', 'entity_type', 'entity_uuid'], 'collab_part_entity_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('collaboration_comments') && $this->indexExists('collaboration_comments', 'collab_comments_entity_idx')) {
            Schema::table('collaboration_comments', function (Blueprint $table): void {
                $table->dropIndex('collab_comments_entity_idx');
            });
        }

        if (Schema::hasTable('collaboration_participants') && $this->indexExists('collaboration_participants', 'collab_part_entity_idx')) {
            Schema::table('collaboration_participants', function (Blueprint $table): void {
                $table->dropIndex('collab_part_entity_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $row = $connection->selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }
};
