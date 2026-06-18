<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 2 preparation — corpus provenance fields and source document keys.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $provenanceForeignKeys = [
        'compliance_domains' => 'cc_domains_src_doc_fk',
        'compliance_controls' => 'cc_controls_src_doc_fk',
        'compliance_requirements' => 'cc_req_src_doc_fk',
        'compliance_guidance_items' => 'cc_guidance_src_doc_fk',
        'compliance_evidence_expectations' => 'cc_ev_exp_src_doc_fk',
    ];

    public function up(): void
    {
        if (Schema::hasTable('compliance_source_documents') && ! Schema::hasColumn('compliance_source_documents', 'key')) {
            Schema::table('compliance_source_documents', function (Blueprint $table) {
                $table->string('key', 64)->nullable()->after('uuid');
            });

            Schema::table('compliance_source_documents', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'key'], 'cc_src_doc_rel_key_uniq');
            });
        }

        foreach ($this->provenanceForeignKeys as $tableName => $foreignKey) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $foreignKey) {
                if (! Schema::hasColumn($tableName, 'source_document_id')) {
                    $table->foreignId('source_document_id')->nullable()->after('uuid')
                        ->constrained('compliance_source_documents', 'id', $foreignKey)
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn($tableName, 'source_page')) {
                    $table->string('source_page', 64)->nullable()->after('source_reference');
                }

                if (! Schema::hasColumn($tableName, 'official_reference')) {
                    $table->text('official_reference')->nullable()->after('source_page');
                }

                if (! Schema::hasColumn($tableName, 'metadata')) {
                    $table->json('metadata')->nullable()->after('tags');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->provenanceForeignKeys, true) as $tableName => $foreignKey) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $foreignKey) {
                if (Schema::hasColumn($tableName, 'source_document_id')) {
                    $table->dropForeign($foreignKey);
                    $table->dropColumn(['source_document_id', 'source_page', 'official_reference', 'metadata']);
                }
            });
        }

        if (Schema::hasColumn('compliance_source_documents', 'key')) {
            Schema::table('compliance_source_documents', function (Blueprint $table) {
                $table->dropUnique('cc_src_doc_rel_key_uniq');
                $table->dropColumn('key');
            });
        }
    }
};
