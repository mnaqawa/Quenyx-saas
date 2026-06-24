<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 8 — Cross-Framework Mapping Foundation.
 *
 * Additive, non-destructive hardening of `compliance_control_objective_mappings` so the
 * mapping foundation can carry release awareness, revision awareness, full provenance, and a
 * deterministic confidence BASIS (official | manual | derived) — none of which were
 * representable before. No data is created or altered; existing rows keep working. The
 * legacy `confidence` (magnitude) column is intentionally left untouched for backward
 * compatibility; the foundation reads/writes `confidence_basis`.
 *
 * Control objectives remain intentionally GLOBAL (no framework_release_id) so they can act as
 * the cross-framework anchor — this is by design, not a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_control_objective_mappings')) {
            return;
        }

        Schema::table('compliance_control_objective_mappings', function (Blueprint $table): void {
            if (! Schema::hasColumn('compliance_control_objective_mappings', 'framework_release_id')) {
                $table->foreignId('framework_release_id')->nullable()->after('control_id')
                    ->constrained('compliance_framework_releases', 'id', 'cc_obj_map_release_fk')->nullOnDelete();
            }

            if (! Schema::hasColumn('compliance_control_objective_mappings', 'corpus_revision_id')) {
                $table->foreignId('corpus_revision_id')->nullable()->after('framework_release_id')
                    ->constrained('compliance_corpus_revisions', 'id', 'cc_obj_map_revision_fk')->nullOnDelete();
            }

            if (! Schema::hasColumn('compliance_control_objective_mappings', 'source_document_id')) {
                $table->foreignId('source_document_id')->nullable()->after('corpus_revision_id')
                    ->constrained('compliance_source_documents', 'id', 'cc_obj_map_srcdoc_fk')->nullOnDelete();
            }

            if (! Schema::hasColumn('compliance_control_objective_mappings', 'confidence_basis')) {
                // official | manual | derived — see App\Enums\Compliance\MappingConfidence
                $table->string('confidence_basis', 16)->nullable()->after('confidence');
            }

            if (! Schema::hasColumn('compliance_control_objective_mappings', 'source_page')) {
                $table->string('source_page', 64)->nullable()->after('source_reference');
            }

            if (! Schema::hasColumn('compliance_control_objective_mappings', 'official_reference')) {
                $table->string('official_reference', 128)->nullable()->after('source_page');
            }
        });

        Schema::table('compliance_control_objective_mappings', function (Blueprint $table): void {
            if (! $this->indexExists('compliance_control_objective_mappings', 'cc_obj_map_release_idx')) {
                $table->index('framework_release_id', 'cc_obj_map_release_idx');
            }
            if (! $this->indexExists('compliance_control_objective_mappings', 'cc_obj_map_confidence_idx')) {
                $table->index('confidence_basis', 'cc_obj_map_confidence_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('compliance_control_objective_mappings')) {
            return;
        }

        Schema::table('compliance_control_objective_mappings', function (Blueprint $table): void {
            if ($this->indexExists('compliance_control_objective_mappings', 'cc_obj_map_release_idx')) {
                $table->dropIndex('cc_obj_map_release_idx');
            }
            if ($this->indexExists('compliance_control_objective_mappings', 'cc_obj_map_confidence_idx')) {
                $table->dropIndex('cc_obj_map_confidence_idx');
            }

            foreach (['framework_release_id', 'corpus_revision_id', 'source_document_id'] as $column) {
                if (Schema::hasColumn('compliance_control_objective_mappings', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['confidence_basis', 'source_page', 'official_reference'] as $column) {
                if (Schema::hasColumn('compliance_control_objective_mappings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $schemaManager = method_exists($connection, 'getDoctrineSchemaManager')
                ? $connection->getDoctrineSchemaManager()
                : null;

            if ($schemaManager !== null) {
                return array_key_exists($index, $schemaManager->listTableIndexes($table));
            }
        } catch (\Throwable) {
            // Fall through to a driver-agnostic check below.
        }

        try {
            return collect(Schema::getIndexes($table))
                ->contains(fn ($definition) => ($definition['name'] ?? null) === $index);
        } catch (\Throwable) {
            return false;
        }
    }
};
