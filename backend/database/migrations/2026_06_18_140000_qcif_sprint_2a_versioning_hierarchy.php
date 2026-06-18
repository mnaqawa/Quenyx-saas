<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QCIF Sprint 2A — Corpus versioning and hierarchy hardening.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_corpus_revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('framework_release_id')
                ->constrained('compliance_framework_releases', 'id', 'cc_rev_release_fk')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('parent_revision_id')->nullable()
                ->constrained('compliance_corpus_revisions', 'id', 'cc_rev_parent_fk')->nullOnDelete();
            $table->foreignId('import_run_id')
                ->constrained('compliance_corpus_import_runs', 'id', 'cc_rev_import_fk')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->json('entity_counts')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['framework_release_id', 'revision_number'], 'cc_rev_release_num_uniq');
            $table->index('status');
            $table->index('checksum_sha256');
        });

        Schema::table('compliance_controls', function (Blueprint $table) {
            if (! Schema::hasColumn('compliance_controls', 'parent_control_id')) {
                $table->foreignId('parent_control_id')->nullable()->after('domain_id')
                    ->constrained('compliance_controls', 'id', 'cc_controls_parent_fk')->nullOnDelete();
            }
            if (! Schema::hasColumn('compliance_controls', 'level')) {
                $table->unsignedTinyInteger('level')->default(1)->after('parent_control_id');
            }
            if (! Schema::hasColumn('compliance_controls', 'display_code')) {
                $table->string('display_code', 128)->nullable()->after('code');
            }
            if (! Schema::hasColumn('compliance_controls', 'normalized_code')) {
                $table->string('normalized_code', 128)->nullable()->after('display_code');
            }
        });

        foreach (['compliance_domains', 'compliance_requirements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'display_code')) {
                    $table->string('display_code', 128)->nullable()->after('code');
                }
                if (! Schema::hasColumn($tableName, 'normalized_code')) {
                    $table->string('normalized_code', 128)->nullable()->after('display_code');
                }
            });
        }

        if (! $this->hasIndex('compliance_domains', 'cc_domains_rel_norm_uniq')) {
            Schema::table('compliance_domains', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'normalized_code'], 'cc_domains_rel_norm_uniq');
            });
        }

        if (! $this->hasIndex('compliance_controls', 'cc_controls_rel_norm_uniq')) {
            Schema::table('compliance_controls', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'normalized_code'], 'cc_controls_rel_norm_uniq');
            });
        }

        if (! $this->hasIndex('compliance_requirements', 'cc_req_rel_norm_uniq')) {
            Schema::table('compliance_requirements', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'normalized_code'], 'cc_req_rel_norm_uniq');
            });
        }

        $this->backfillCanonicalCodes();
    }

    public function down(): void
    {
        Schema::table('compliance_requirements', function (Blueprint $table) {
            if ($this->hasIndex('compliance_requirements', 'cc_req_rel_norm_uniq')) {
                $table->dropUnique('cc_req_rel_norm_uniq');
            }
            if (Schema::hasColumn('compliance_requirements', 'display_code')) {
                $table->dropColumn(['display_code', 'normalized_code']);
            }
        });

        Schema::table('compliance_controls', function (Blueprint $table) {
            if ($this->hasIndex('compliance_controls', 'cc_controls_rel_norm_uniq')) {
                $table->dropUnique('cc_controls_rel_norm_uniq');
            }
            if (Schema::hasColumn('compliance_controls', 'parent_control_id')) {
                $table->dropForeign('cc_controls_parent_fk');
                $table->dropColumn(['parent_control_id', 'level', 'display_code', 'normalized_code']);
            }
        });

        Schema::table('compliance_domains', function (Blueprint $table) {
            if ($this->hasIndex('compliance_domains', 'cc_domains_rel_norm_uniq')) {
                $table->dropUnique('cc_domains_rel_norm_uniq');
            }
            if (Schema::hasColumn('compliance_domains', 'display_code')) {
                $table->dropColumn(['display_code', 'normalized_code']);
            }
        });

        Schema::dropIfExists('compliance_corpus_revisions');
    }

    private function backfillCanonicalCodes(): void
    {
        foreach (DB::table('compliance_domains')->whereNull('normalized_code')->get() as $row) {
            $display = $row->display_code ?? $row->code;
            DB::table('compliance_domains')->where('id', $row->id)->update([
                'display_code' => $display,
                'normalized_code' => $this->normalizeCode((string) $display),
            ]);
        }

        foreach (DB::table('compliance_controls')->whereNull('normalized_code')->get() as $row) {
            $display = $row->display_code ?? $row->code;
            DB::table('compliance_controls')->where('id', $row->id)->update([
                'display_code' => $display,
                'normalized_code' => $this->normalizeCode((string) $display),
            ]);
        }

        foreach (DB::table('compliance_requirements')->whereNull('normalized_code')->get() as $row) {
            $display = $row->display_code ?? $row->code;
            DB::table('compliance_requirements')->where('id', $row->id)->update([
                'display_code' => $display,
                'normalized_code' => $this->normalizeCode((string) $display),
            ]);
        }
    }

    private function normalizeCode(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\.\*\/\-\s]+/', '_', $normalized) ?? $normalized;
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : null;
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
};
