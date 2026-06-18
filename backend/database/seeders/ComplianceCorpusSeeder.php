<?php

namespace Database\Seeders;

use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFramework;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Minimal QCIF Sprint 1 seed: NCA ECC-2:2024 framework shell + evidence type catalog.
 * Does NOT seed controls — those are imported via human-curated corpus files.
 */
class ComplianceCorpusSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedFramework();
        $this->seedEvidenceTypes();
    }

    private function seedFramework(): void
    {
        ComplianceFramework::query()->updateOrCreate(
            [
                'key' => 'nca-ecc',
                'version_code' => '2:2024',
            ],
            [
                'code' => 'NCA-ECC',
                'slug' => 'nca-ecc-2-2024',
                'title_en' => 'NCA Essential Cybersecurity Controls',
                'title_ar' => 'الضوابط الأساسية للأمن السيبراني',
                'description_en' => 'National Cybersecurity Authority Essential Cybersecurity Controls (ECC) version 2:2024.',
                'description_ar' => 'الضوابط الأساسية للأمن السيبراني الصادرة عن الهيئة الوطنية للأمن السيبراني — الإصدار 2:2024.',
                'authority' => 'National Cybersecurity Authority',
                'authority_en' => 'National Cybersecurity Authority',
                'authority_ar' => 'الهيئة الوطنية للأمن السيبراني',
                'effective_from' => '2024-01-01',
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'sort_order' => 1,
                'source_reference' => 'NCA ECC-2:2024 official publication (human curation required for control-level import)',
                'tags' => ['saudi', 'nca', 'ecc', 'cybersecurity'],
            ],
        );
    }

    private function seedEvidenceTypes(): void
    {
        $types = [
            ['key' => 'policy', 'code' => 'POL', 'title_en' => 'Policy', 'title_ar' => 'سياسة'],
            ['key' => 'procedure', 'code' => 'PROC', 'title_en' => 'Procedure', 'title_ar' => 'إجراء'],
            ['key' => 'log', 'code' => 'LOG', 'title_en' => 'Log', 'title_ar' => 'سجل'],
            ['key' => 'configuration', 'code' => 'CFG', 'title_en' => 'Configuration', 'title_ar' => 'إعداد'],
            ['key' => 'screenshot', 'code' => 'SCR', 'title_en' => 'Screenshot', 'title_ar' => 'لقطة شاشة'],
            ['key' => 'report', 'code' => 'RPT', 'title_en' => 'Report', 'title_ar' => 'تقرير'],
            ['key' => 'approval', 'code' => 'APR', 'title_en' => 'Approval', 'title_ar' => 'موافقة'],
            ['key' => 'ticket', 'code' => 'TKT', 'title_en' => 'Ticket', 'title_ar' => 'تذكرة'],
            ['key' => 'inventory', 'code' => 'INV', 'title_en' => 'Inventory', 'title_ar' => 'جرد'],
            ['key' => 'audit_record', 'code' => 'AUD', 'title_en' => 'Audit Record', 'title_ar' => 'سجل تدقيق'],
            ['key' => 'training_record', 'code' => 'TRN', 'title_en' => 'Training Record', 'title_ar' => 'سجل تدريب'],
        ];

        foreach ($types as $index => $type) {
            ComplianceEvidenceType::query()->updateOrCreate(
                ['key' => $type['key']],
                [
                    'code' => $type['code'],
                    'slug' => Str::slug($type['key']),
                    'title_en' => $type['title_en'],
                    'title_ar' => $type['title_ar'],
                    'description_en' => "Recommended evidence type: {$type['title_en']}",
                    'description_ar' => "نوع دليل موصى به: {$type['title_ar']}",
                    'status' => PublicationStatus::Published,
                    'published_at' => now(),
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
