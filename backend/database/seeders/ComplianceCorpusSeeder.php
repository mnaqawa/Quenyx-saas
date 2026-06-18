<?php

namespace Database\Seeders;

use App\Enums\Compliance\AuthorityStatus;
use App\Enums\Compliance\PublicationStatus;
use App\Models\Compliance\ComplianceAuthority;
use App\Models\Compliance\ComplianceEvidenceType;
use App\Models\Compliance\ComplianceFramework;
use App\Models\Compliance\ComplianceFrameworkRelease;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * QCIF Sprint 1.1 seed: NCA authority, ECC framework family, ECC-2:2024 release, evidence types.
 */
class ComplianceCorpusSeeder extends Seeder
{
    public function run(): void
    {
        $authority = $this->seedAuthority();
        $framework = $this->seedFrameworkFamily($authority);
        $this->seedFrameworkRelease($framework);
        $this->seedEvidenceTypes();
    }

    private function seedAuthority(): ComplianceAuthority
    {
        return ComplianceAuthority::query()->updateOrCreate(
            ['key' => 'nca'],
            [
                'name_en' => 'National Cybersecurity Authority',
                'name_ar' => 'الهيئة الوطنية للأمن السيبراني',
                'short_name' => 'NCA',
                'country_code' => 'SA',
                'website_url' => 'https://nca.gov.sa',
                'description_en' => 'Saudi Arabia national regulator for cybersecurity.',
                'description_ar' => 'الجهة الوطنية المنظمة للأمن السيبراني في المملكة العربية السعودية.',
                'status' => AuthorityStatus::Active,
                'sort_order' => 1,
            ],
        );
    }

    private function seedFrameworkFamily(ComplianceAuthority $authority): ComplianceFramework
    {
        return ComplianceFramework::query()->updateOrCreate(
            ['key' => 'nca-ecc'],
            [
                'authority_id' => $authority->id,
                'code' => 'NCA-ECC',
                'slug' => 'nca-ecc',
                'title_en' => 'NCA Essential Cybersecurity Controls',
                'title_ar' => 'الضوابط الأساسية للأمن السيبراني',
                'description_en' => 'National Cybersecurity Authority Essential Cybersecurity Controls (ECC) framework family.',
                'description_ar' => 'إطار الضوابط الأساسية للأمن السيبراني الصادر عن الهيئة الوطنية للأمن السيبراني.',
                'status' => PublicationStatus::Published,
                'sort_order' => 1,
                'tags' => ['saudi', 'nca', 'ecc', 'cybersecurity'],
                'metadata' => ['family' => true],
            ],
        );
    }

    private function seedFrameworkRelease(ComplianceFramework $framework): ComplianceFrameworkRelease
    {
        return ComplianceFrameworkRelease::query()->updateOrCreate(
            [
                'framework_id' => $framework->id,
                'version_code' => '2:2024',
            ],
            [
                'release_code' => 'ECC-2:2024',
                'title_en' => 'NCA ECC Version 2:2024',
                'title_ar' => 'الضوابط الأساسية للأمن السيبراني — الإصدار 2:2024',
                'description_en' => 'NCA Essential Cybersecurity Controls release 2:2024.',
                'description_ar' => 'إصدار الضوابط الأساسية للأمن السيبراني 2:2024.',
                'effective_date' => '2024-01-01',
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'source_reference' => 'NCA ECC-2:2024 official publication (human curation required for control-level import)',
                'metadata' => ['seed' => 'sprint-1.1'],
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
