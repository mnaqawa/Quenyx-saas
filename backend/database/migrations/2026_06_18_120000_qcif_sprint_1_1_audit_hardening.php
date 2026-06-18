<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 1.1 audit hardening — post-110000 follow-up.
 *
 * - Rename source document file metadata columns to official_* (external metadata only)
 * - Drop legacy framework-scoped unique constraints if still present
 * - Enforce NOT NULL on framework_release_id for release-scoped corpus entities
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameSourceDocumentFileMetadataColumns();
        $this->dropLegacyCorpusUniqueConstraints();
        $this->enforceReleaseIdNotNull('compliance_domains');
        $this->enforceReleaseIdNotNull('compliance_controls');
        $this->enforceReleaseIdNotNull('compliance_requirements');
        $this->normalizeLegacyImportRunStatuses();
    }

    public function down(): void
    {
        $this->revertReleaseIdNullable('compliance_requirements');
        $this->revertReleaseIdNullable('compliance_controls');
        $this->revertReleaseIdNullable('compliance_domains');
        $this->revertSourceDocumentFileMetadataColumns();
    }

    private function renameSourceDocumentFileMetadataColumns(): void
    {
        if (! Schema::hasTable('compliance_source_documents')) {
            return;
        }

        if (Schema::hasColumn('compliance_source_documents', 'file_name')) {
            DB::statement('ALTER TABLE compliance_source_documents CHANGE file_name official_file_name VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('compliance_source_documents', 'file_mime')) {
            DB::statement('ALTER TABLE compliance_source_documents CHANGE file_mime official_file_mime VARCHAR(128) NULL');
        }

        if (Schema::hasColumn('compliance_source_documents', 'file_size')) {
            DB::statement('ALTER TABLE compliance_source_documents CHANGE file_size official_file_size BIGINT UNSIGNED NULL');
        }
    }

    private function revertSourceDocumentFileMetadataColumns(): void
    {
        if (! Schema::hasTable('compliance_source_documents')) {
            return;
        }

        if (Schema::hasColumn('compliance_source_documents', 'official_file_name')) {
            DB::statement('ALTER TABLE compliance_source_documents CHANGE official_file_name file_name VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('compliance_source_documents', 'official_file_mime')) {
            DB::statement('ALTER TABLE compliance_source_documents CHANGE official_file_mime file_mime VARCHAR(128) NULL');
        }

        if (Schema::hasColumn('compliance_source_documents', 'official_file_size')) {
            DB::statement('ALTER TABLE compliance_source_documents CHANGE official_file_size file_size BIGINT UNSIGNED NULL');
        }
    }

    private function dropLegacyCorpusUniqueConstraints(): void
    {
        $legacyUniques = [
            'compliance_domains' => [
                'index' => 'compliance_domains_framework_id_code_unique',
                'fk_column' => 'framework_id',
                'replacement_index' => 'cc_domains_framework_id_idx',
            ],
            'compliance_controls' => [
                'index' => 'compliance_controls_framework_id_code_unique',
                'fk_column' => 'framework_id',
                'replacement_index' => 'cc_controls_framework_id_idx',
            ],
            'compliance_requirements' => [
                'index' => 'compliance_requirements_control_id_code_unique',
                'fk_column' => 'control_id',
                'replacement_index' => 'cc_req_control_id_idx',
            ],
        ];

        foreach ($legacyUniques as $table => $config) {
            $indexName = $config['index'];
            if (! $this->hasIndex($table, $indexName)) {
                continue;
            }

            $this->ensureForeignKeySupportIndex(
                $table,
                $config['fk_column'],
                $indexName,
                $config['replacement_index'],
            );

            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }

    /**
     * MySQL requires an index on FK columns. Legacy composite uniques often
     * served as that index — add a dedicated one before dropping them.
     */
    private function ensureForeignKeySupportIndex(
        string $table,
        string $column,
        string $indexBeingDropped,
        string $replacementIndexName,
    ): void {
        if ($this->hasIndex($table, $replacementIndexName)) {
            return;
        }

        if ($this->hasLeftPrefixIndex($table, $column, $indexBeingDropped)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$replacementIndexName}` (`{$column}`)");
    }

    private function hasLeftPrefixIndex(string $table, string $column, ?string $excludeIndex = null): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $rows = $connection->select(
            'SELECT index_name, seq_in_index FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND column_name = ?
             ORDER BY index_name, seq_in_index',
            [$database, $table, $column]
        );

        foreach ($rows as $row) {
            if ($excludeIndex !== null && $row->index_name === $excludeIndex) {
                continue;
            }

            if ((int) $row->seq_in_index === 1) {
                return true;
            }
        }

        return false;
    }

    private function enforceReleaseIdNotNull(string $table): void
    {
        if (! Schema::hasColumn($table, 'framework_release_id')) {
            return;
        }

        if ($this->columnIsNotNull($table, 'framework_release_id')) {
            return;
        }

        $nullCount = DB::table($table)->whereNull('framework_release_id')->count();
        if ($nullCount > 0) {
            throw new RuntimeException(
                "Cannot enforce NOT NULL on {$table}.framework_release_id: {$nullCount} row(s) still null. Backfill before re-running."
            );
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `framework_release_id` BIGINT UNSIGNED NOT NULL");
    }

    private function revertReleaseIdNullable(string $table): void
    {
        if (! Schema::hasColumn($table, 'framework_release_id')) {
            return;
        }

        if (! $this->columnIsNotNull($table, 'framework_release_id')) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `framework_release_id` BIGINT UNSIGNED NULL");
    }

    private function normalizeLegacyImportRunStatuses(): void
    {
        if (! Schema::hasTable('compliance_corpus_import_runs')) {
            return;
        }

        DB::table('compliance_corpus_import_runs')
            ->where('status', 'running')
            ->update(['status' => 'importing']);
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return count($result) > 0;
    }

    private function columnIsNotNull(string $table, string $column): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
            [$database, $table, $column]
        );

        return isset($result[0]) && $result[0]->IS_NULLABLE === 'NO';
    }
};
