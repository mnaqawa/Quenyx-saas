<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * QCIF Sprint 1.1 — Corpus architecture hardening.
 *
 * Introduces authorities, framework families vs releases, source documents,
 * and release-scoped corpus entities.
 *
 * Idempotent: safe to re-run after a partial failure (e.g. mid-backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_authorities')) {
            Schema::create('compliance_authorities', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('key', 64)->unique();
                $table->string('name_en');
                $table->string('name_ar');
                $table->string('short_name', 64)->nullable();
                $table->string('country_code', 8)->nullable();
                $table->string('website_url')->nullable();
                $table->text('description_en')->nullable();
                $table->text('description_ar')->nullable();
                $table->string('status', 16)->default('active');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('status');
            });
        }

        if (! Schema::hasColumn('compliance_frameworks', 'authority_id')) {
            Schema::table('compliance_frameworks', function (Blueprint $table) {
                $table->foreignId('authority_id')->nullable()->after('uuid')
                    ->constrained('compliance_authorities', 'id', 'cc_fw_authority_fk')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('compliance_frameworks', 'metadata')) {
            Schema::table('compliance_frameworks', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('tags');
            });
        }

        if (! Schema::hasTable('compliance_framework_releases')) {
            Schema::create('compliance_framework_releases', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('framework_id')->constrained('compliance_frameworks', 'id', 'cc_fw_rel_fw_fk')->cascadeOnDelete();
                $table->string('release_code', 64);
                $table->string('version_code', 32);
                $table->string('title_en');
                $table->string('title_ar');
                $table->text('description_en')->nullable();
                $table->text('description_ar')->nullable();
                $table->date('effective_date')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('deprecated_at')->nullable();
                $table->timestamp('retired_at')->nullable();
                $table->string('status', 32)->default('draft');
                $table->foreignId('superseded_by_release_id')->nullable()
                    ->constrained('compliance_framework_releases', 'id', 'cc_fw_rel_superseded_fk')->nullOnDelete();
                $table->text('source_reference')->nullable();
                $table->json('migration_reference')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['framework_id', 'version_code'], 'cc_fw_rel_ver_uniq');
                $table->unique(['framework_id', 'release_code'], 'cc_fw_rel_code_uniq');
                $table->index('status');
                $table->index('effective_date');
            });
        }

        $this->migrateLegacyFrameworksToReleases();

        if (! Schema::hasTable('compliance_source_documents')) {
            Schema::create('compliance_source_documents', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('framework_release_id')
                    ->constrained('compliance_framework_releases', 'id', 'cc_src_doc_rel_fk')->cascadeOnDelete();
                $table->string('title_en');
                $table->string('title_ar');
                $table->string('document_type', 32)->default('framework');
                $table->string('language', 16)->default('bilingual');
                $table->string('source_url')->nullable();
                $table->string('official_file_name')->nullable();
                $table->string('official_file_mime', 128)->nullable();
                $table->unsignedBigInteger('official_file_size')->nullable();
                $table->string('checksum_sha256', 64)->nullable();
                $table->text('source_reference')->nullable();
                $table->date('publication_date')->nullable();
                $table->date('effective_date')->nullable();
                $table->string('status', 32)->default('draft');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['framework_release_id', 'document_type'], 'cc_src_doc_rel_type_idx');
                $table->index('status');
            });
        }

        if (! Schema::hasColumn('compliance_domains', 'framework_release_id')) {
            Schema::table('compliance_domains', function (Blueprint $table) {
                $table->foreignId('framework_release_id')->nullable()->after('framework_id')
                    ->constrained('compliance_framework_releases', 'id', 'cc_domains_rel_fk')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('compliance_controls', 'framework_release_id')) {
            Schema::table('compliance_controls', function (Blueprint $table) {
                $table->foreignId('framework_release_id')->nullable()->after('framework_id')
                    ->constrained('compliance_framework_releases', 'id', 'cc_controls_rel_fk')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('compliance_requirements', 'framework_release_id')) {
            Schema::table('compliance_requirements', function (Blueprint $table) {
                $table->foreignId('framework_release_id')->nullable()->after('control_id')
                    ->constrained('compliance_framework_releases', 'id', 'cc_req_rel_fk')->cascadeOnDelete();
            });
        }

        $this->backfillCorpusReleaseReferences();

        if ($this->hasIndex('compliance_domains', 'compliance_domains_framework_id_code_unique')) {
            Schema::table('compliance_domains', function (Blueprint $table) {
                $table->dropUnique(['framework_id', 'code']);
            });
        }

        if (! $this->hasIndex('compliance_domains', 'cc_domains_rel_code_uniq')) {
            Schema::table('compliance_domains', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'code'], 'cc_domains_rel_code_uniq');
            });
        }

        if ($this->hasIndex('compliance_controls', 'compliance_controls_framework_id_code_unique')) {
            Schema::table('compliance_controls', function (Blueprint $table) {
                $table->dropUnique(['framework_id', 'code']);
            });
        }

        if (! $this->hasIndex('compliance_controls', 'cc_controls_rel_code_uniq')) {
            Schema::table('compliance_controls', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'code'], 'cc_controls_rel_code_uniq');
            });
        }

        if (! $this->hasIndex('compliance_requirements', 'cc_req_rel_ctrl_code_uniq')) {
            Schema::table('compliance_requirements', function (Blueprint $table) {
                $table->unique(['framework_release_id', 'control_id', 'code'], 'cc_req_rel_ctrl_code_uniq');
            });
        }

        if (! Schema::hasColumn('compliance_corpus_import_runs', 'framework_release_id')) {
            Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
                $table->foreignId('framework_release_id')->nullable()->after('framework_id')
                    ->constrained('compliance_framework_releases', 'id', 'cc_import_rel_fk')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('compliance_corpus_import_runs', 'source_document_id')) {
            Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
                $table->foreignId('source_document_id')->nullable()->after('framework_release_id')
                    ->constrained('compliance_source_documents', 'id', 'cc_import_src_doc_fk')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('compliance_corpus_import_runs', 'import_type')) {
            Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
                $table->string('import_type', 16)->default('import')->after('format');
            });
        }

        if (! Schema::hasColumn('compliance_corpus_import_runs', 'summary')) {
            Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
                $table->json('summary')->nullable()->after('content_hash');
            });
        }

        if (! Schema::hasColumn('compliance_corpus_import_runs', 'failed_at')) {
            Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
                $table->timestamp('failed_at')->nullable()->after('completed_at');
            });
        }

        if (! Schema::hasColumn('compliance_corpus_import_runs', 'rollback_of_import_run_id')) {
            Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
                $table->foreignId('rollback_of_import_run_id')->nullable()->after('failed_at')
                    ->constrained('compliance_corpus_import_runs', 'id', 'cc_import_rollback_fk')->nullOnDelete();
            });
        }

        $this->backfillImportRunReleaseReferences();

        $runs = DB::table('compliance_corpus_import_runs')->orderBy('id')->get();
        foreach ($runs as $run) {
            $updates = [];
            if (Schema::hasColumn('compliance_corpus_import_runs', 'import_type') && empty($run->import_type)) {
                $updates['import_type'] = ($run->dry_run ?? false) ? 'dry_run' : 'import';
            }
            if (Schema::hasColumn('compliance_corpus_import_runs', 'summary') && $run->summary === null && $run->stats !== null) {
                $updates['summary'] = $run->stats;
            }
            if ($run->status === 'running') {
                $updates['status'] = 'importing';
            }
            if ($updates !== []) {
                DB::table('compliance_corpus_import_runs')->where('id', $run->id)->update($updates);
            }
        }

        if (Schema::hasColumn('compliance_frameworks', 'version_code')) {
            Schema::table('compliance_frameworks', function (Blueprint $table) {
                if ($this->foreignKeyExists('compliance_frameworks', 'superseded_by_framework_id')) {
                    $table->dropForeign(['superseded_by_framework_id']);
                }
                $table->dropColumn([
                    'version_code',
                    'effective_from',
                    'effective_to',
                    'authority',
                    'authority_en',
                    'authority_ar',
                    'published_at',
                    'deprecated_at',
                    'source_reference',
                    'superseded_by_framework_id',
                    'migration_reference',
                ]);
            });
        }

        if ($this->hasIndex('compliance_frameworks', 'compliance_frameworks_key_version_code_unique')) {
            Schema::table('compliance_frameworks', function (Blueprint $table) {
                $table->dropUnique(['key', 'version_code']);
            });
        }

        if (! $this->hasIndex('compliance_frameworks', 'cc_frameworks_key_uniq')) {
            Schema::table('compliance_frameworks', function (Blueprint $table) {
                $table->unique('key', 'cc_frameworks_key_uniq');
            });
        }
    }

    public function down(): void
    {
        Schema::table('compliance_corpus_import_runs', function (Blueprint $table) {
            if ($this->foreignKeyExists('compliance_corpus_import_runs', 'rollback_of_import_run_id')) {
                $table->dropForeign('cc_import_rollback_fk');
            }
            if ($this->foreignKeyExists('compliance_corpus_import_runs', 'source_document_id')) {
                $table->dropForeign('cc_import_src_doc_fk');
            }
            if ($this->foreignKeyExists('compliance_corpus_import_runs', 'framework_release_id')) {
                $table->dropForeign('cc_import_rel_fk');
            }
            $table->dropColumn(array_filter([
                Schema::hasColumn('compliance_corpus_import_runs', 'framework_release_id') ? 'framework_release_id' : null,
                Schema::hasColumn('compliance_corpus_import_runs', 'source_document_id') ? 'source_document_id' : null,
                Schema::hasColumn('compliance_corpus_import_runs', 'import_type') ? 'import_type' : null,
                Schema::hasColumn('compliance_corpus_import_runs', 'summary') ? 'summary' : null,
                Schema::hasColumn('compliance_corpus_import_runs', 'failed_at') ? 'failed_at' : null,
                Schema::hasColumn('compliance_corpus_import_runs', 'rollback_of_import_run_id') ? 'rollback_of_import_run_id' : null,
            ]));
        });

        Schema::table('compliance_requirements', function (Blueprint $table) {
            if ($this->hasIndex('compliance_requirements', 'cc_req_rel_ctrl_code_uniq')) {
                $table->dropUnique('cc_req_rel_ctrl_code_uniq');
            }
            if ($this->foreignKeyExists('compliance_requirements', 'framework_release_id')) {
                $table->dropForeign('cc_req_rel_fk');
            }
            if (Schema::hasColumn('compliance_requirements', 'framework_release_id')) {
                $table->dropColumn('framework_release_id');
            }
        });

        Schema::table('compliance_controls', function (Blueprint $table) {
            if ($this->hasIndex('compliance_controls', 'cc_controls_rel_code_uniq')) {
                $table->dropUnique('cc_controls_rel_code_uniq');
            }
            if ($this->foreignKeyExists('compliance_controls', 'framework_release_id')) {
                $table->dropForeign('cc_controls_rel_fk');
            }
            if (Schema::hasColumn('compliance_controls', 'framework_release_id')) {
                $table->dropColumn('framework_release_id');
            }
            if (! $this->hasIndex('compliance_controls', 'compliance_controls_framework_id_code_unique')) {
                $table->unique(['framework_id', 'code']);
            }
        });

        Schema::table('compliance_domains', function (Blueprint $table) {
            if ($this->hasIndex('compliance_domains', 'cc_domains_rel_code_uniq')) {
                $table->dropUnique('cc_domains_rel_code_uniq');
            }
            if ($this->foreignKeyExists('compliance_domains', 'framework_release_id')) {
                $table->dropForeign('cc_domains_rel_fk');
            }
            if (Schema::hasColumn('compliance_domains', 'framework_release_id')) {
                $table->dropColumn('framework_release_id');
            }
            if (! $this->hasIndex('compliance_domains', 'compliance_domains_framework_id_code_unique')) {
                $table->unique(['framework_id', 'code']);
            }
        });

        Schema::dropIfExists('compliance_source_documents');
        Schema::dropIfExists('compliance_framework_releases');

        Schema::table('compliance_frameworks', function (Blueprint $table) {
            if ($this->foreignKeyExists('compliance_frameworks', 'authority_id')) {
                $table->dropForeign('cc_fw_authority_fk');
            }
            if ($this->hasIndex('compliance_frameworks', 'cc_frameworks_key_uniq')) {
                $table->dropUnique('cc_frameworks_key_uniq');
            }
            if (Schema::hasColumn('compliance_frameworks', 'authority_id')) {
                $table->dropColumn(['authority_id', 'metadata']);
            }
            if (! Schema::hasColumn('compliance_frameworks', 'version_code')) {
                $table->string('version_code', 32)->default('2:2024');
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('authority')->nullable();
                $table->string('authority_en')->nullable();
                $table->string('authority_ar')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('deprecated_at')->nullable();
                $table->text('source_reference')->nullable();
                $table->foreignId('superseded_by_framework_id')->nullable()
                    ->constrained('compliance_frameworks')->nullOnDelete();
                $table->json('migration_reference')->nullable();
                $table->unique(['key', 'version_code']);
            }
        });

        Schema::dropIfExists('compliance_authorities');
    }

    private function migrateLegacyFrameworksToReleases(): void
    {
        if (! Schema::hasColumn('compliance_frameworks', 'version_code')) {
            return;
        }

        if (DB::table('compliance_framework_releases')->exists()) {
            return;
        }

        $existingAuthority = DB::table('compliance_authorities')->where('key', 'nca')->first();
        $ncaAuthorityId = $existingAuthority?->id ?? DB::table('compliance_authorities')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'key' => 'nca',
            'name_en' => 'National Cybersecurity Authority',
            'name_ar' => 'الهيئة الوطنية للأمن السيberاني',
            'short_name' => 'NCA',
            'country_code' => 'SA',
            'status' => 'active',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $frameworkRows = DB::table('compliance_frameworks')->orderBy('id')->get();
        $familyByKey = [];

        foreach ($frameworkRows as $row) {
            $authorityId = $ncaAuthorityId;

            if (! isset($familyByKey[$row->key])) {
                DB::table('compliance_frameworks')->where('id', $row->id)->update([
                    'authority_id' => $authorityId,
                    'slug' => $row->slug ?? $row->key,
                    'metadata' => json_encode([
                        'legacy_tags' => json_decode($row->tags ?? 'null', true),
                        'migrated_from_sprint1' => true,
                    ]),
                ]);
                $familyByKey[$row->key] = $row->id;
            }

            $familyId = $familyByKey[$row->key];
            $versionCode = $row->version_code ?? '2:2024';

            DB::table('compliance_framework_releases')->insert([
                'uuid' => (string) Str::uuid(),
                'framework_id' => $familyId,
                'release_code' => 'ECC-'.$versionCode,
                'version_code' => $versionCode,
                'title_en' => $row->title_en,
                'title_ar' => $row->title_ar,
                'description_en' => $row->description_en,
                'description_ar' => $row->description_ar,
                'effective_date' => $row->effective_from,
                'published_at' => $row->published_at,
                'deprecated_at' => $row->deprecated_at,
                'status' => $row->status ?? 'draft',
                'source_reference' => $row->source_reference,
                'migration_reference' => $row->migration_reference,
                'metadata' => json_encode(['legacy_framework_id' => $row->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($row->id !== $familyId) {
                DB::table('compliance_frameworks')->where('id', $row->id)->delete();
            }
        }
    }

    private function backfillCorpusReleaseReferences(): void
    {
        if (! Schema::hasColumn('compliance_domains', 'framework_release_id')) {
            return;
        }

        $releases = DB::table('compliance_framework_releases')->get();

        foreach ($releases as $release) {
            $legacyFrameworkId = null;
            $metadata = json_decode($release->metadata ?? '{}', true);
            if (is_array($metadata) && isset($metadata['legacy_framework_id'])) {
                $legacyFrameworkId = (int) $metadata['legacy_framework_id'];
            }

            $frameworkId = (int) $release->framework_id;
            $targetFrameworkIds = array_values(array_unique(array_filter([$legacyFrameworkId, $frameworkId])));

            if ($targetFrameworkIds === []) {
                continue;
            }

            DB::table('compliance_domains')
                ->whereIn('framework_id', $targetFrameworkIds)
                ->whereNull('framework_release_id')
                ->update([
                    'framework_release_id' => $release->id,
                    'framework_id' => $frameworkId,
                ]);

            DB::table('compliance_controls')
                ->whereIn('framework_id', $targetFrameworkIds)
                ->whereNull('framework_release_id')
                ->update([
                    'framework_release_id' => $release->id,
                    'framework_id' => $frameworkId,
                ]);

            $controlIds = DB::table('compliance_controls')
                ->where('framework_release_id', $release->id)
                ->pluck('id');

            if ($controlIds->isNotEmpty()) {
                DB::table('compliance_requirements')
                    ->whereIn('control_id', $controlIds)
                    ->whereNull('framework_release_id')
                    ->update(['framework_release_id' => $release->id]);
            }
        }
    }

    private function backfillImportRunReleaseReferences(): void
    {
        if (! Schema::hasColumn('compliance_corpus_import_runs', 'framework_release_id')) {
            return;
        }

        $releases = DB::table('compliance_framework_releases')->get();

        foreach ($releases as $release) {
            $legacyFrameworkId = null;
            $metadata = json_decode($release->metadata ?? '{}', true);
            if (is_array($metadata) && isset($metadata['legacy_framework_id'])) {
                $legacyFrameworkId = (int) $metadata['legacy_framework_id'];
            }

            $frameworkId = (int) $release->framework_id;
            $targetFrameworkIds = array_values(array_unique(array_filter([$legacyFrameworkId, $frameworkId])));

            if ($targetFrameworkIds === []) {
                continue;
            }

            DB::table('compliance_corpus_import_runs')
                ->whereIn('framework_id', $targetFrameworkIds)
                ->whereNull('framework_release_id')
                ->update(['framework_release_id' => $release->id]);
        }
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

    private function foreignKeyExists(string $table, string $column): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT 1 FROM information_schema.key_column_usage
             WHERE table_schema = ? AND table_name = ? AND column_name = ? AND referenced_table_name IS NOT NULL
             LIMIT 1',
            [$database, $table, $column]
        );

        return count($result) > 0;
    }
};
