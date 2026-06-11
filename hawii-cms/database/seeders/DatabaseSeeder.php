<?php

namespace Database\Seeders;

use App\Models\CaseStudy;
use App\Models\Industry;
use App\Models\Page;
use App\Models\Post;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Stat;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Staff accounts ----
        User::updateOrCreate(['email' => 'admin@hawii.tech'], [
            'name' => 'Hawii Admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        User::updateOrCreate(['email' => 'editor@hawii.tech'], [
            'name' => 'Hawii Editor',
            'password' => Hash::make('password'),
            'role' => 'editor',
        ]);

        // ---- Settings ----
        $settings = [
            'site_name' => ['en' => 'Hawii.tech', 'ar' => 'حاوي تك'],
            'tagline' => [
                'en' => 'Enterprise IT & AI, delivered with confidence',
                'ar' => 'حلول تقنية وذكاء اصطناعي للمؤسسات بثقة',
            ],
            'hero_title' => [
                'en' => 'Engineering smarter business with IT & AI',
                'ar' => 'نهندس أعمالاً أذكى بالتقنية والذكاء الاصطناعي',
            ],
            'hero_subtitle' => [
                'en' => 'Hawii.tech builds secure infrastructure, custom software, and production-grade AI that helps organizations scale, automate, and stay ahead.',
                'ar' => 'تبني حاوي تك بنية تحتية آمنة وبرمجيات مخصّصة وأنظمة ذكاء اصطناعي جاهزة للإنتاج تساعد المؤسسات على النمو والأتمتة والتقدّم.',
            ],
            'hero_badge' => [
                'en' => 'ISO 27001 aligned · SOC 2 practices · 24/7 support',
                'ar' => 'متوافق مع ISO 27001 · ممارسات SOC 2 · دعم على مدار الساعة',
            ],
            'contact_email' => 'hello@hawii.tech',
            'contact_phone' => '+1 (555) 040-1920',
            'contact_location' => ['en' => 'Remote-first · Worldwide', 'ar' => 'عن بُعد · حول العالم'],
            'social_linkedin' => '#',
            'social_x' => '#',
            'social_github' => '#',
            'brand_color' => '#2563EB',
            'accent_color' => '#F97316',
            'footer_note' => [
                'en' => '© 2026 Hawii.tech — IT & AI Solutions. All rights reserved.',
                'ar' => '© 2026 حاوي تك — حلول تقنية وذكاء اصطناعي. جميع الحقوق محفوظة.',
            ],
        ];
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // ---- Stats ----
        $stats = [
            ['150+', ['en' => 'Projects delivered', 'ar' => 'مشروع منجز']],
            ['99.9%', ['en' => 'Uptime SLA', 'ar' => 'نسبة التشغيل']],
            ['40%', ['en' => 'Avg. cost saved', 'ar' => 'متوسط التوفير']],
            ['24/7', ['en' => 'Expert support', 'ar' => 'دعم الخبراء']],
        ];
        foreach ($stats as $i => [$value, $label]) {
            Stat::updateOrCreate(
                ['value' => $value],
                ['label' => $label, 'sort' => $i, 'is_published' => true]
            );
        }

        // ---- Services (IT + AI pillars) ----
        $services = [
            ['cloud-devops', 'it', 'cloud', ['en' => 'Cloud & DevOps', 'ar' => 'السحابة و DevOps'], ['en' => 'Migration, IaC, CI/CD and cost-optimized cloud on AWS, Azure & GCP.', 'ar' => 'ترحيل وبنية تحتية ككود و CI/CD وسحابة محسّنة التكلفة على AWS و Azure و GCP.']],
            ['cybersecurity', 'it', 'shield', ['en' => 'Cybersecurity', 'ar' => 'الأمن السيبراني'], ['en' => 'Threat detection, audits, zero-trust architecture and compliance.', 'ar' => 'كشف التهديدات والتدقيق وبنية انعدام الثقة والامتثال.']],
            ['custom-software', 'it', 'code', ['en' => 'Custom Software', 'ar' => 'برمجيات مخصّصة'], ['en' => 'Web, mobile & enterprise apps built to scale and last.', 'ar' => 'تطبيقات ويب وجوال وأنظمة مؤسسية مبنية للنمو والاستمرار.']],
            ['managed-it', 'it', 'workflow', ['en' => 'Managed IT & Support', 'ar' => 'الإدارة والدعم التقني'], ['en' => 'Proactive monitoring, helpdesk and infrastructure management 24/7.', 'ar' => 'مراقبة استباقية ومكتب مساعدة وإدارة بنية تحتية على مدار الساعة.']],
            ['generative-ai', 'ai', 'bot', ['en' => 'Generative AI & LLMs', 'ar' => 'الذكاء التوليدي ونماذج اللغة'], ['en' => 'Copilots, RAG assistants and LLM apps tailored to your data.', 'ar' => 'مساعدون أذكياء وأنظمة RAG وتطبيقات نماذج لغوية مصمّمة على بياناتك.']],
            ['ml-analytics', 'ai', 'chart', ['en' => 'ML & Predictive Analytics', 'ar' => 'التعلّم الآلي والتحليلات التنبؤية'], ['en' => 'Forecasting, recommendations and decision intelligence from your data.', 'ar' => 'تنبؤات وتوصيات وذكاء قرارات مستخلص من بياناتك.']],
            ['computer-vision', 'ai', 'eye', ['en' => 'Computer Vision', 'ar' => 'الرؤية الحاسوبية'], ['en' => 'Detection, OCR and quality control for images and video at scale.', 'ar' => 'كشف واستخراج نصوص ومراقبة جودة للصور والفيديو على نطاق واسع.']],
            ['ai-automation', 'ai', 'bolt', ['en' => 'AI Automation & Agents', 'ar' => 'الأتمتة والوكلاء الأذكياء'], ['en' => 'Autonomous workflows that cut manual work and speed up operations.', 'ar' => 'مهام سير عمل ذاتية تقلّل العمل اليدوي وتُسرّع العمليات.']],
        ];
        foreach ($services as $i => [$slug, $pillar, $icon, $title, $excerpt]) {
            Service::updateOrCreate(['slug' => $slug], [
                'pillar' => $pillar, 'icon' => $icon, 'title' => $title,
                'excerpt' => $excerpt, 'sort' => $i, 'is_published' => true,
            ]);
        }

        // ---- Industries ----
        $industries = [
            ['finance', 'building-columns', ['en' => 'Finance', 'ar' => 'المالية']],
            ['healthcare', 'heart-pulse', ['en' => 'Healthcare', 'ar' => 'الرعاية الصحية']],
            ['retail', 'shopping-bag', ['en' => 'Retail', 'ar' => 'التجزئة']],
            ['government', 'landmark', ['en' => 'Government', 'ar' => 'القطاع الحكومي']],
            ['logistics', 'truck', ['en' => 'Logistics', 'ar' => 'الخدمات اللوجستية']],
            ['education', 'graduation-cap', ['en' => 'Education', 'ar' => 'التعليم']],
        ];
        foreach ($industries as $i => [$slug, $icon, $name]) {
            Industry::updateOrCreate(['slug' => $slug], [
                'icon' => $icon, 'name' => $name, 'sort' => $i, 'is_published' => true,
            ]);
        }

        // ---- Testimonials ----
        Testimonial::updateOrCreate(['initials' => 'SA'], [
            'quote' => [
                'en' => '“Hawii.tech rebuilt our cloud platform and shipped an AI assistant that now handles 60% of support tickets. They felt like part of our team from day one.”',
                'ar' => '«أعادت حاوي تك بناء منصّتنا السحابية وأطلقت مساعداً ذكياً يتولّى الآن 60٪ من طلبات الدعم. شعرنا أنهم جزء من فريقنا منذ اليوم الأول.»',
            ],
            'author' => ['en' => 'Sara Al-Amin', 'ar' => 'سارة الأمين'],
            'role' => ['en' => 'CTO, Meridian Group', 'ar' => 'الرئيسة التقنية، مجموعة ميريديان'],
            'sort' => 0, 'is_published' => true,
        ]);

        // ---- Case study (sample) ----
        CaseStudy::updateOrCreate(['slug' => 'meridian-cloud-ai'], [
            'client' => ['en' => 'Meridian Group', 'ar' => 'مجموعة ميريديان'],
            'title' => ['en' => 'Cloud rebuild + AI support assistant', 'ar' => 'إعادة بناء سحابية + مساعد دعم ذكي'],
            'summary' => ['en' => 'A full cloud migration and an LLM assistant that now resolves 60% of support tickets automatically.', 'ar' => 'ترحيل سحابي كامل ومساعد ذكي يحل الآن 60٪ من طلبات الدعم تلقائياً.'],
            'industry' => ['en' => 'Finance', 'ar' => 'المالية'],
            'body' => ['en' => '<p>We re-architected Meridian\'s infrastructure on a cost-optimized cloud foundation, then layered a retrieval-augmented AI assistant on top of their knowledge base.</p>', 'ar' => '<p>أعدنا هندسة بنية ميريديان على أساس سحابي محسّن التكلفة، ثم أضفنا مساعداً ذكياً معتمداً على قاعدة معارفهم.</p>'],
            'sort' => 0, 'is_published' => true,
        ]);

        // ---- Blog post (sample) ----
        $admin = User::where('email', 'admin@hawii.tech')->first();
        Post::updateOrCreate(['slug' => 'why-rag-beats-fine-tuning'], [
            'user_id' => $admin?->id,
            'title' => ['en' => 'Why RAG often beats fine-tuning for enterprise AI', 'ar' => 'لماذا يتفوّق RAG غالباً على الضبط الدقيق في ذكاء المؤسسات'],
            'excerpt' => ['en' => 'A practical look at when retrieval-augmented generation is the smarter, cheaper path to a useful AI assistant.', 'ar' => 'نظرة عملية على متى يكون التوليد المعزّز بالاسترجاع الخيار الأذكى والأقل تكلفة لمساعد ذكي مفيد.'],
            'body' => ['en' => '<p>Fine-tuning has its place, but for most enterprise knowledge tasks, retrieval-augmented generation (RAG) delivers accuracy and freshness at a fraction of the cost.</p>', 'ar' => '<p>للضبط الدقيق مكانه، لكن لمعظم مهام المعرفة المؤسسية، يوفّر التوليد المعزّز بالاسترجاع (RAG) دقة وحداثة بجزء بسيط من التكلفة.</p>'],
            'is_published' => true, 'published_at' => now()->subDays(3),
        ]);

        // ---- Standalone pages ----
        Page::updateOrCreate(['slug' => 'about'], [
            'title' => ['en' => 'About Hawii.tech', 'ar' => 'عن حاوي تك'],
            'body' => [
                'en' => '<p>Hawii.tech is a technology partner for organizations that want to scale with confidence. We combine senior engineering, security-first delivery, and production-grade AI to turn ambitious ideas into reliable systems.</p><p>From cloud infrastructure to custom software and applied AI, we own outcomes — from the first whiteboard to production and beyond.</p>',
                'ar' => '<p>حاوي تك شريك تقني للمؤسسات التي تريد النمو بثقة. نجمع بين هندسة الخبراء والتسليم الآمن أولاً والذكاء الاصطناعي الجاهز للإنتاج لتحويل الأفكار الطموحة إلى أنظمة موثوقة.</p><p>من البنية السحابية إلى البرمجيات المخصّصة والذكاء الاصطناعي التطبيقي، نتحمّل مسؤولية النتائج — من أول فكرة وحتى الإنتاج وما بعده.</p>',
            ],
            'is_published' => true,
        ]);

        Page::updateOrCreate(['slug' => 'privacy'], [
            'title' => ['en' => 'Privacy Policy', 'ar' => 'سياسة الخصوصية'],
            'body' => ['en' => '<p>This is placeholder privacy policy content. Replace it from the admin panel.</p>', 'ar' => '<p>هذا محتوى تجريبي لسياسة الخصوصية. استبدله من لوحة التحكم.</p>'],
            'is_published' => true,
        ]);

        Page::updateOrCreate(['slug' => 'terms'], [
            'title' => ['en' => 'Terms of Service', 'ar' => 'شروط الخدمة'],
            'body' => ['en' => '<p>This is placeholder terms content. Replace it from the admin panel.</p>', 'ar' => '<p>هذا محتوى تجريبي للشروط. استبدله من لوحة التحكم.</p>'],
            'is_published' => true,
        ]);
    }
}
