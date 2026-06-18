<?php

/**
 * One-time builder for governance/domain.json (Sprint 3B).
 * Run: php backend/database/corpus/nca/ecc-2-2024/governance/build-domain.php
 */

declare(strict_types=1);

function norm(string $code): string
{
    $normalized = strtolower(trim($code));
    $normalized = preg_replace('/[\.\*\/\-\s]+/', '_', $normalized) ?? $normalized;
    $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;

    return trim($normalized, '_');
}

function control(
    string $code,
    string $titleEn,
    string $titleAr,
    string $textEn,
    string $textAr,
    string $subdomain,
    int $sort,
    string $page,
): array {
    return [
        'code' => $code,
        'display_code' => $code,
        'normalized_code' => norm($code),
        'title_en' => $titleEn,
        'title_ar' => $titleAr,
        'description_en' => $textEn,
        'description_ar' => $textAr,
        'source_document_key' => 'nca-ecc-2-2024-en',
        'source_reference' => "NCA ECC-2:2024 EN - Domain 1 Governance - Control {$code}",
        'official_reference' => "ECC-2:2024:1:{$code}",
        'source_page' => $page,
        'sort_order' => $sort,
        'level' => 1,
        'metadata' => ['subdomain' => $subdomain],
        'requirements' => [[
            'code' => $code,
            'display_code' => $code,
            'normalized_code' => norm($code),
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'requirement_text_en' => $textEn,
            'requirement_text_ar' => $textAr,
            'source_document_key' => 'nca-ecc-2-2024-en',
            'source_reference' => "NCA ECC-2:2024 EN - Domain 1 Governance - Control {$code}",
            'official_reference' => "ECC-2:2024:1:{$code}",
            'source_page' => $page,
            'metadata' => ['subdomain' => $subdomain],
        ]],
    ];
}

$controls = [
    control('1-1-1', 'Cybersecurity strategy identification, documentation, and approval', 'تحديد وتوثيق واعتماد استراتيجية الأمن السيبراني', 'The cybersecurity strategy of the entity shall be identified, documented, and approved, and it shall be supported by the head of the entity or his/her delegate (Hereinafter referred to as the "Authorized Official"). The strategy goals shall be in line with the relevant legislative and regulatory requirements.', 'يجب تحديد وتوثيق واعتماد إستراتيجية الأمن السيبراني للجهة ودعمها من قبل رئيس الجهة أو من ينيبه (ويشار له في هذه الضوابط بـاسم "صاحب الصلاحية")، وأن تتماشى الأهداف الإستراتيجية للأمن السيبراني للجهة مع المتطلبات التشريعية والتنظيمية ذات العلاقة.', '1-1', 1, '13'),
    control('1-1-2', 'Cybersecurity strategy action plan execution', 'تنفيذ خطة عمل استراتيجية الأمن السيبراني', 'The entity shall execute an action plan to apply the cybersecurity strategy.', 'يجب العمل على تنفيذ خطة عمل لتطبيق إستراتيجية الأمن السيبراني من قبل الجهة.', '1-1', 2, '13'),
    control('1-1-3', 'Cybersecurity strategy review', 'مراجعة استراتيجية الأمن السيبراني', 'The cybersecurity strategy shall be reviewed at planned intervals (or in case of changes to the relevant legislative and regulatory requirements).', 'يجب مراجعة إستراتيجية الأمن السيبراني على فترات زمنية مخطط لها (أو في حالة حدوث تغييرات في المتطلبات التشريعية والتنظيمية ذات العلاقة).', '1-1', 3, '13'),
    control('1-2-1', 'Cybersecurity department establishment', 'إنشاء إدارة الأمن السيبراني', 'A department for cybersecurity shall be established within the entity. This department shall be independent from the Information Technology and Communications Department (As per High Order No. 37140, dated 14/08/1438H.). It is recommended that the Cybersecurity Department reports directly to the head of the entity or his/her delegate while ensuring that this does not result in a conflict of interests.', 'يجب إنشاء إدارة معنية بالأمن السيبراني في الجهة مستقلة عن إدارة تقنية المعلومات والاتصالات (ICT/ IT) (وفقاً للأمر السامي الكريم رقم 37140 وتاريخ 14 / 8 / 1438 هـ). ويفضل ارتباطها مباشرة برئيس الجهة أو من ينيبه، مع الأخذ بالاعتبار عدم تعارض المصالح.', '1-2', 4, '13'),
    control('1-2-2', 'Cybersecurity positions staffing', 'شغل وظائف الأمن السيبراني', 'All cybersecurity positions shall be filled out with full-time and qualified Saudi cybersecurity professionals.', 'يجب شغل جميع وظائف الأمن السيبراني من قبل مواطنين متفرغين وذوي كفاءة في مجال الأمن السيبراني.', '1-2', 5, '13'),
    control('1-2-3', 'Cybersecurity supervisory committee', 'لجنة إشرافية للأمن السيبراني', "A cybersecurity supervisory committee shall be established pursuant to the instruction of the entity's Authorized Official to ensure compliance with, support for, and monitoring of the implementation of the cybersecurity programs and regulations. The committee's members, responsibilities, and governance framework shall be identified, documented, and approved. The committee shall include the head of the cybersecurity department as a member. It is recommended that the committee reports directly to the head of the entity or his/her delegate while ensuring that this does not result in a conflict of interests.", 'يجب إنشاء لجنة إشرافية للأمن السيبراني بتوجيه من صاحب الصلاحية للجهة لضمان التزام ودعم ومتابعة تطبيق برامج وتشريعات الأمن السيبراني، ويتم تحديد وتوثيق واعتماد أعضاء اللجنة ومسؤولياتها وإطار حوكمة أعمالها على أن يكون رئيس الإدارة المعنية بالأمن السيبراني أحد أعضائها. ويفضل ارتباطها مباشرة برئيس الجهة أو من ينيبه، مع الأخذ بالاعتبار عدم تعارض المصالح.', '1-2', 6, '13'),
    control('1-3-1', 'Cybersecurity policies and procedures identification', 'تحديد سياسات وإجراءات الأمن السيبراني', "The cybersecurity department of the entity shall identify and document cybersecurity policies and procedures, including the cybersecurity controls and requirements, and have them approved by the entity's Authorized Official, and communicate them to the relevant personnel and parties inside the entity.", 'يجب على الإدارة المعنية بالأمن السيبراني في الجهة تحديد سياسات وإجراءات الأمن السيبراني وما تشمله من ضوابط ومتطلبات الأمن السيبراني، وتوثيقها واعتمادها من قبل صاحب الصلاحية في الجهة، كما يجب نشرها إلى ذوي العلاقة من العاملين في الجهة والأطراف المعنية بها.', '1-3', 7, '14'),
    control('1-3-2', 'Cybersecurity policies and procedures implementation', 'تطبيق سياسات وإجراءات الأمن السيبراني', 'The cybersecurity department shall ensure that the cybersecurity policies and procedures, including the relevant controls and requirements, are implemented at the entity.', 'يجب على الإدارة المعنية بالأمن السيبراني ضمان تطبيق سياسات وإجراءات الأمن السيبراني في الجهة وما تشمله من ضوابط ومتطلبات.', '1-3', 8, '14'),
    control('1-3-3', 'Technical security standards support', 'دعم المعايير التقنية الأمنية', 'The cybersecurity policies and procedures shall be supported by technical security standards (e.g. technical security standards for firewall, databases, operating systems, etc.).', 'يجب أن تكون سياسات وإجراءات الأمن السيبراني مدعومة بمعايير تقنية أمنية (على سبيل المثال: المعايير التقنية الأمنية لجدار الحماية وقواعد البيانات، وأنظمة التشغيل، إلخ).', '1-3', 9, '14'),
    control('1-3-4', 'Cybersecurity policies and procedures review', 'مراجعة سياسات وإجراءات الأمن السيبراني', 'The cybersecurity policies and procedures shall be reviewed and updated at planned intervals (or in case of changes to the relevant legislative and regulatory requirements and standards). Changes shall be documented and approved.', 'يجب مراجعة سياسات وإجراءات ومعايير الأمن السيبراني وتحديثها على فترات زمنية مخطط لها (أو في حالة حدوث تغييرات في المتطلبات التشريعية والتنظيمية والمعايير ذات العلاقة)، كما يجب توثيق التغييرات واعتمادها.', '1-3', 10, '14'),
    control('1-4-1', 'Cybersecurity governance roles and responsibilities', 'أدوار ومسؤوليات حوكمة الأمن السيبراني', "The Authorized Official shall identify, document, and approve the organizational structure, roles, and responsibilities of the entity's cybersecurity governance, and assign the persons concerned therewith. The necessary support shall be provided for the implementation thereof while ensuring that this does not result in a conflict of interests.", 'يجب على صاحب الصلاحية تحديد وتوثيق واعتماد الهيكل التنظيمي للحوكمة والأدوار والمسؤوليات الخاصة بالأمن السيبراني للجهة، وتكليف الأشخاص المعنيين بها، كما يجب تقديم الدعم اللازم لإنفاذ ذلك، مع الأخذ بالاعتبار عدم تعارض المصالح.', '1-4', 11, '14'),
    control('1-4-2', 'Cybersecurity roles and responsibilities review', 'مراجعة أدوار ومسؤوليات الأمن السيبراني', 'The cybersecurity roles and responsibilities within the entity shall be reviewed and updated at planned intervals (or in case of changes to the relevant legislative and regulatory requirements).', 'يجب مراجعة أدوار ومسؤوليات الأمن السيبراني في الجهة وتحديثها على فترات زمنية مخطط لها (أو في حالة حدوث تغييرات في المتطلبات التشريعية والتنظيمية ذات العلاقة).', '1-4', 12, '14'),
    control('1-5-1', 'Cybersecurity risk management methodology', 'منهجية إدارة مخاطر الأمن السيبراني', 'The cybersecurity department of the entity shall identify, document, and approve the cybersecurity risk management methodology and procedures within the entity, in accordance with considerations of confidentiality, and the integrity and availability of information and technology assets.', 'يجب على الإدارة المعنية بالأمن السيبراني في الجهة تحديد وتوثيق واعتماد منهجية وإجراءات إدارة مخاطر الأمن السيبراني في الجهة. وذلك وفقاً لاعتبارات السرية وتوافر وسلامة الأصول المعلوماتية والتقنية.', '1-5', 13, '15'),
    control('1-5-2', 'Cybersecurity risk management implementation', 'تطبيق إدارة مخاطر الأمن السيبراني', 'The cybersecurity department shall implement the cybersecurity risk management methodology and procedures within the entity.', 'يجب على الإدارة المعنية بالأمن السيبراني تطبيق منهجية وإجراءات إدارة مخاطر الأمن السيبراني في الجهة.', '1-5', 14, '15'),
    control('1-5-3', 'Cybersecurity risk assessment procedures', 'إجراءات تقييم مخاطر الأمن السيبراني', "The cybersecurity risk assessment procedures shall be implemented at least in the following cases:\n1.5.3.1 At early stage of technology projects.\n1.5.3.2 Before making major changes to technology infrastructure.\n1.5.3.3 During planning to obtain third party services.\n1.5.3.4 During planning and before the release of new technology services and products.", 'يجب تنفيذ إجراءات تقييم مخاطر الأمن السيبراني بحد أدنى في الحالات التالية: 1-3-5-1 في مرحلة مبكرة من المشاريع التقنية. 2-3-5-1 قبل إجراء تغيير جوهري في البنية التقنية. 3-3-5-1 عند التخطيط للحصول على خدمات طرف خارجي. 4-3-5-1 عند التخطيط وقبل إطلاق منتجات وخدمات تقنية جديدة.', '1-5', 15, '15'),
    control('1-5-4', 'Cybersecurity risk management review', 'مراجعة إدارة مخاطر الأمن السيبراني', 'The cybersecurity risk management methodology and procedures shall be reviewed and updated at planned intervals (or in case of changes to the relevant legislative and regulatory requirements and standards). Changes shall be documented and approved.', 'يجب مراجعة منهجية وإجراءات إدارة مخاطر الأمن السيبراني وتحديثها على فترات زمنية مخطط لها (أو في حالة حدوث تغييرات في المتطلبات التشريعية والتنظيمية والمعايير ذات العلاقة)، كما يجب توثيق التغييرات واعتمادها.', '1-5', 16, '15'),
    control('1-6-1', 'Cybersecurity in project management', 'الأمن السيبراني في إدارة المشاريع', 'Cybersecurity requirements shall be included in the project management methodology and procedures and in the information and technology asset change management within the entity to ensure identifying and managing cybersecurity risks as part of the technology project lifecycle. The cybersecurity requirements shall be a key part of the requirements for technology projects.', 'يجب تضمين متطلبات الأمن السيبراني في منهجية وإجراءات إدارة المشاريع وفي إدارة التغيير على الأصول المعلوماتية والتقنية في الجهة لضمان تحديد مخاطر الأمن السيبراني ومعالجتها كجزء من دورة حياة المشروع التقني، وأن تكون متطلبات الأمن السيبراني جزء أساسي من متطلبات المشاريع التقنية.', '1-6', 17, '15'),
    control('1-6-2', 'Cybersecurity requirements for project and asset changes', 'متطلبات الأمن السيبراني لتغييرات المشاريع والأصول', "The cybersecurity requirements for project management and information and technology asset changes within the entity shall include the following as a minimum:\n1.6.2.1 Vulnerability assessment and remediation.\n1.6.2.2 Reviewing secure configuration and hardening and updates packages before launching projects and changes.", 'يجب أن تغطي متطلبات الأمن السيبراني لإدارة المشاريع والتغييرات على الأصول المعلوماتية والتقنية للجهة بحد أدنى ما يلي: 1-2-6-1 تقييم الثغرات ومعالجتها. 2-2-6-1 إجراء مراجعة للإعدادات والتحصين (Secure Configuration and Hardening) وحزم التحديثات قبل إطلاق وتدشين المشاريع والتغييرات.', '1-6', 18, '15'),
    control('1-6-3', 'Cybersecurity requirements for software development', 'متطلبات الأمن السيبراني لتطوير البرمجيات', "The cybersecurity requirements for software and application development projects within the entity shall include the following as a minimum:\n1.6.3.1 Using the secure coding standards.\n1.6.3.2 Using trusted and licensed sources for software development tools and libraries.\n1.6.3.3 Conducting compliance test for software against the cybersecurity requirements within the entity.\n1.6.3.4 Secure integration between applications.\n1.6.3.5 Reviewing secure configuration and hardening and updates packages before launching software products", 'يجب أن تغطي متطلبات الأمن السيبراني لمشاريع تطوير التطبيقات والبرمجيات الخاصة للجهة بحد أدنى ما يلي: 1-3-6-1 استخدام معايير التطوير الآمن للتطبيقات (Secure Coding Standards). 2-3-6-1 استخدام مصادر مرخصة وموثوقة لأدوات تطوير التطبيقات والمكتبات الخاصة بها (Libraries). 3-3-6-1 إجراء اختبار للتحقق من مدى استيفاء التطبيقات للمتطلبات الأمنية السيبرانية للجهة. 4-3-6-1 أمن التكامل (Integration) بين التطبيقات. 5-3-6-1 إجراء مراجعة للإعدادات والتحصين (Secure Configuration and Hardening) وحزم التحديثات قبل إطلاق وتدشين التطبيقات.', '1-6', 19, '15'),
    control('1-6-4', 'Cybersecurity project management requirements review', 'مراجعة متطلبات الأمن السيبراني في إدارة المشاريع', 'The cybersecurity requirements for project management within the entity shall be periodically reviewed.', 'يجب مراجعة متطلبات الأمن السيبراني في إدارة المشاريع في الجهة دورياً.', '1-6', 20, '15'),
    control('1-7-1', 'Compliance with international cybersecurity commitments', 'الالتزام بالاتفاقيات والالتزامات الدولية للأمن السيبراني', 'If there are nationally approved international agreements or commitments that include cybersecurity requirements, the entity shall identify and comply with these requirements.', 'في حال وجود اتفاقيات أو التزامات دولية معتمدة محلياً تتضمن متطلبات خاصة بالأمن السيبراني، فيجب على الجهة الالتزام بتلك المتطلبات.', '1-7', 21, '16'),
    control('1-8-1', 'Periodic cybersecurity controls review', 'المراجعة الدورية لضوابط الأمن السيبراني', 'The cybersecurity department of the entity shall periodically review the implementation of cybersecurity controls by the entity.', 'يجب على الإدارة المعنية بالأمن السيبراني في الجهة مراجعة تطبيق ضوابط الأمن السيبراني دورياً.', '1-8', 22, '16'),
    control('1-8-2', 'Independent cybersecurity controls audit', 'التدقيق المستقل لضوابط الأمن السيبراني', 'The implementation of cybersecurity controls by the entity shall be reviewed and audited by parties other than the cybersecurity department at the entity, provided that the audit and review are to be conducted independently while considering the principle of conflict of interest, as per the Generally Accepted Auditing Standards (GAAS) and the relevant legislative and regulatory requirements.', 'يجب مراجعة وتدقيق تطبيق ضوابط الأمن السيبراني في الجهة، من قبل أطراف مستقلة عن الإدارة المعنية بالأمن السيبراني (مثل الإدارة المعنية بالمراجعة في الجهة). على أن تتم المراجعة والتدقيق بشكل مستقل يراعى فيه مبدأ عدم تعارض المصالح، وذلك وفقاً للمعايير العامة المقبولة للمراجعة والتدقيق والمتطلبات التشريعية والتنظيمية ذات العلاقة.', '1-8', 23, '16'),
    control('1-8-3', 'Cybersecurity audit results documentation', 'توثيق نتائج مراجعة وتدقيق الأمن السيبراني', 'The results of cybersecurity audits and reviews shall be documented and presented to the cybersecurity supervisory committee and the Authorized Official. Results shall include the audit and review scope, observations, recommendations, corrective actions, and remediation plans.', 'يجب توثيق نتائج مراجعة وتدقيق الأمن السيبراني، وعرضها على اللجنة الإشرافية للأمن السيبراني وصاحب الصلاحية. كما يجب أن تشتمل النتائج على نطاق المراجعة والتدقيق، والملاحظات المكتشفة، والتوصيات والإجراءات التصحيحية، وخطة معالجة الملاحظات.', '1-8', 24, '16'),
    control('1-9-1', 'Personnel cybersecurity requirements definition', 'تحديد متطلبات الأمن السيبراني للعاملين', 'Cybersecurity requirements for personnel of the entity shall be identified, documented, and approved prior to, during, and upon the end or termination of their employment.', 'يجب تحديد وتوثيق واعتماد متطلبات الأمن السيبراني المتعلقة بالعاملين قبل توظيفهم وأثناء عملهم وعند انتهاء/إنهاء عملهم في الجهة.', '1-9', 25, '17'),
    control('1-9-2', 'Personnel cybersecurity requirements implementation', 'تطبيق متطلبات الأمن السيبراني للعاملين', 'Cybersecurity requirements for personnel of the entity shall be implemented.', 'يجب تطبيق متطلبات الأمن السيبراني المتعلقة بالعاملين في الجهة.', '1-9', 26, '17'),
    control('1-9-3', 'Pre-employment cybersecurity requirements', 'متطلبات الأمن السيبراني قبل بدء العمل', "Cybersecurity requirements prior to the commencement of the employment relationship between personnel and the entity shall include the following as a minimum:\n1.9.3.1 Incorporating the personnel's cybersecurity responsibilities clauses and non disclosure clauses in their employment contracts with the entity (including during and after employment end/termination with the entity).\n1.9.3.2 Conducting screening or vetting for personnel in cybersecurity positions and technical positions with critical and privileged powers.", 'يجب أن تغطي متطلبات الأمن السيبراني قبل بدء علاقة العاملين المهنية بالجهة بحد أدنى ما يلي: 1-3-9-1 تضمين مسؤوليات الأمن السيبراني وبنود المحافظة على سرية المعلومات (Non-Disclosure Clauses) في عقود العاملين في الجهة (لتشمل خلال وبعد انتهاء/إنهاء العلاقة الوظيفية مع الجهة). 2-3-9-1 إجراء المسح الأمني (Screening or Vetting) للعاملين في وظائف الأمن السيبراني والوظائف التقنية ذات الصلاحيات الهامة والحساسة.', '1-9', 27, '17'),
    control('1-9-4', 'During-employment cybersecurity requirements', 'متطلبات الأمن السيبراني أثناء العمل', "Cybersecurity requirements for personnel during their employment relationship with the entity shall include the following as a minimum:\n1.9.4.1 Cybersecurity awareness (during on-boarding and during employment).\n1.9.4.2 Implementation and compliance with cybersecurity requirements, as per the entity's cybersecurity policies, procedures, and operations.", 'يجب أن تغطي متطلبات الأمن السيبراني خلال علاقة العاملين المهنية بالجهة بحد أدنى ما يلي: 1-4-9-1 التوعية بالأمن السيبراني (عند بداية المهنة الوظيفية وخلالها). 2-4-9-1 تطبيق متطلبات الأمن السيبراني والالتزام بها وفقاً لسياسات وإجراءات وعمليات الأمن السيبراني للجهة.', '1-9', 28, '17'),
    control('1-9-5', 'Privileged access revocation on employment termination', 'إلغاء الصلاحيات عند انتهاء العمل', "The personnel's privileged powers shall be reviewed and revoked immediately upon the end/termination of their employment with the entity.", 'يجب مراجعة وإلغاء الصلاحيات للعاملين مباشرة بعد انتهاء/إنهاء الخدمة المهنية لهم بالجهة.', '1-9', 29, '17'),
    control('1-9-6', 'Personnel cybersecurity requirements review', 'مراجعة متطلبات الأمن السيبراني للعاملين', 'Cybersecurity requirements for personnel of the entity shall be periodically reviewed.', 'يجب مراجعة متطلبات الأمن السيبراني المتعلقة بالعاملين في الجهة دورياً.', '1-9', 30, '17'),
    control('1-10-1', 'Cybersecurity awareness program development', 'تطوير برنامج التوعية بالأمن السيبراني', 'A cybersecurity awareness program, delivered through multiple channels, shall be periodically developed and approved by the entity to strengthen the awareness about cybersecurity, cyber threats, and risks, and to build a positive cybersecurity awareness culture.', 'يجب تطوير واعتماد برنامج للتوعية بالأمن السيبراني في الجهة من خلال قنوات متعددة دورياً، وذلك لتعزيز الوعي بالأمن السيبراني وتهديداته ومخاطره، وبناء ثقافة إيجابية للأمن السيبراني.', '1-10', 31, '18'),
    control('1-10-2', 'Cybersecurity awareness program implementation', 'تطبيق برنامج التوعية بالأمن السيبراني', 'The approved cybersecurity awareness program shall be implemented within the entity.', 'يجب تطبيق البرنامج المعتمد للتوعية بالأمن السيبراني في الجهة.', '1-10', 32, '18'),
    control('1-10-3', 'Cybersecurity awareness program coverage', 'محتوى برنامج التوعية بالأمن السيبراني', "The cybersecurity awareness program shall include how to protect the entity against the most important and latest cyber risks and threats, including:\n1.10.3.1 Secure handling of email services, especially phishing emails.\n1.10.3.2 Secure handling of mobile devices and storage media.\n1.10.3.3 Secure Internet browsing.\n1.10.3.4 Secure usage of social media.", 'يجب أن يغطي برنامج التوعية بالأمن السيبراني كيفية حماية الجهة من أهم المخاطر والتهديدات السيبرانية وما يستجد منها، بما في ذلك: 1-3-10-1 التعامل الآمن مع خدمات البريد الإلكتروني خصوصاً مع رسائل التصيد الإلكتروني. 2-3-10-1 التعامل الآمن مع الأجهزة المحمولة ووسائط التخزين. 3-3-10-1 التعامل الآمن مع خدمات تصفح الإنترنت. 4-3-10-1 التعامل الآمن مع وسائل التواصل الاجتماعي.', '1-10', 33, '18'),
    control('1-10-4', 'Specialized cybersecurity training', 'التدريب المتخصص بالأمن السيبراني', "Specialized skills and necessary training shall be provided to personnel in positions that are linked directly to cybersecurity within the entity. Such skills and training shall be classified in line with their cybersecurity responsibilities, including:\n1.10.4.1 Cybersecurity department personnel.\n1.10.4.2 Personnel working on software/application development and those working on information and technology assets of the entity.\n1.10.4.3 Executive and supervisory positions.", 'يجب توفير المهارات المتخصصة والتدريب اللازم للعاملين في المجالات الوظيفية ذات العلاقة المباشرة بالأمن السيبراني في الجهة، وتصنيفها بما يتماشى مع مسؤولياتهم الوظيفية فيما يتعلق بالأمن السيبراني، بما في ذلك: 1-4-10-1 موظفو الإدارة المعنية بالأمن السيبراني. 2-4-10-1 الموظفون العاملون في تطوير البرامج والتطبيقات والموظفون المشغلون للأصول المعلوماتية والتقنية للجهة. 3-4-10-1 الوظائف الإشرافية والتنفيذية.', '1-10', 34, '18'),
    control('1-10-5', 'Cybersecurity awareness program review', 'مراجعة برنامج التوعية بالأمن السيبراني', 'The implementation of cybersecurity awareness program within the entity shall be periodically reviewed.', 'يجب مراجعة تطبيق برنامج التوعية بالأمن السيبراني في الجهة دورياً.', '1-10', 35, '18'),
];

$payload = [
    '$schema' => 'quenyx/qcif/domain-batch/v1.0',
    'status' => 'validated',
    'reviewed_by' => null,
    'reviewed_at' => null,
    'notes' => 'Sprint 3B - Governance domain curated from official NCA ECC-2:2024 EN/AR publications. Pending human approval before production import.',
    'metadata' => [
        'curation_sprint' => '3B',
        'domain_slug' => 'governance',
        'source_publication' => 'ECC-2:2024',
        'control_count' => count($controls),
        'requirement_count' => count($controls),
    ],
    'domain' => [
        'code' => '1',
        'display_code' => 'ECC-1',
        'normalized_code' => 'ecc_1',
        'title_en' => 'Cybersecurity Governance',
        'title_ar' => 'حوكمة الأمن السيبراني',
        'description_en' => 'Main Domain 1 of NCA Essential Cybersecurity Controls (ECC-2:2024).',
        'description_ar' => 'المجال الرئيسي الأول من الضوابط الأساسية للأمن السيبراني (ECC-2:2024).',
        'source_document_key' => 'nca-ecc-2-2024-en',
        'source_reference' => 'NCA ECC-2:2024 EN - Main Domain 1 Cybersecurity Governance',
        'official_reference' => 'ECC-2:2024:1',
        'source_page' => '13',
        'sort_order' => 1,
        'metadata' => [
            'subdomain_count' => 10,
            'official_subdomains' => ['1-1', '1-2', '1-3', '1-4', '1-5', '1-6', '1-7', '1-8', '1-9', '1-10'],
        ],
        'controls' => $controls,
    ],
];

$out = __DIR__.'/domain.json';
file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, 'Wrote '.$out.' ('.count($controls)." controls)\n");
