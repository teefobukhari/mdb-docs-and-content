<?php
/**
 * كأس العالم 2026 — حقائق تاريخية وأرقام كأس العالم لكل منتخب.
 *
 * wc_team_facts($code) ترجع لكل دولة:
 *   wc / wcAr   سطر سجل كأس العالم (ألقاب / أفضل نتيجة)
 *   facts / factsAr   حقائق من تاريخ كرة القدم وكأس العالم
 *
 * ملف منفصل ومُحاط بـ function_exists حتى يبقى اختيارياً: إن لم يُنشر، تعمل
 * الخريطة كالمعتاد بدون هذا القسم الإضافي.
 */

if (!function_exists('wc_team_facts')) {
    function wc_team_facts(string $code = ''): array {
        static $f = null;
        if ($f === null) {
            $f = [
                /* ---- Hosts / CONCACAF ---- */
                'mx' => ['wc'=>'17 appearances · Best: Quarter-finals (1970, 1986)','wcAr'=>'17 مشاركة · الأفضل: ربع النهائي (1970، 1986)',
                    'facts'=>['First nation to host the World Cup twice (1970, 1986) — and a third in 2026.','Hosted the 1970 final, the first World Cup broadcast in colour.'],
                    'factsAr'=>['أول دولة تستضيف كأس العالم مرتين (1970، 1986) — والثالثة في 2026.','استضافت نهائي 1970، أول مونديال يُبث بالألوان.']],
                'ca' => ['wc'=>'2 appearances (1986, 2022)','wcAr'=>'مشاركتان (1986، 2022)',
                    'facts'=>['Co-host of 2026 — its first World Cup on home soil.','Alphonso Davies scored Canada\'s first-ever World Cup goal in 2022.'],
                    'factsAr'=>['مستضيف مشارك 2026 — أول مونديال له على أرضه.','سجّل ألفونسو ديفيز أول هدف لكندا في تاريخ كأس العالم 2022.']],
                'us' => ['wc'=>'11 appearances · Best: 3rd place (1930)','wcAr'=>'11 مشاركة · الأفضل: المركز الثالث (1930)',
                    'facts'=>['Reached the semi-finals at the very first World Cup in 1930.','Hosted USA 94, still the best-attended World Cup in history.'],
                    'factsAr'=>['بلغ نصف نهائي أول كأس عالم 1930.','استضاف مونديال 1994، الأعلى حضوراً جماهيرياً في التاريخ.']],
                'cr' => ['wc'=>'6 appearances · Best: Quarter-finals (2014)','wcAr'=>'6 مشاركات · الأفضل: ربع النهائي (2014)',
                    'facts'=>['Topped a 2014 group containing Uruguay, Italy and England.','Keylor Navas\' heroics made him a Real Madrid signing soon after.'],
                    'factsAr'=>['تصدّر مجموعة 2014 التي ضمّت أوروغواي وإيطاليا وإنجلترا.','تألق كيلور نافاس قاده للانتقال إلى ريال مدريد بعدها بقليل.']],
                'pa' => ['wc'=>'1 appearance (2018)','wcAr'=>'مشاركة واحدة (2018)',
                    'facts'=>['Qualified for its first World Cup in 2018; the day was declared a holiday.'],
                    'factsAr'=>['تأهل لأول مونديال 2018، وأُعلن ذلك اليوم عطلة وطنية.']],
                'jm' => ['wc'=>'1 appearance (1998)','wcAr'=>'مشاركة واحدة (1998)',
                    'facts'=>['First English-speaking Caribbean nation to reach a World Cup.','Beat Japan 2-1 in their final 1998 group game.'],
                    'factsAr'=>['أول دولة كاريبية ناطقة بالإنجليزية تبلغ كأس العالم.','فازت على اليابان 2-1 في آخر مباريات مجموعتها 1998.']],

                /* ---- CONMEBOL ---- */
                'ar' => ['wc'=>'3-time champions (1978, 1986, 2022)','wcAr'=>'بطل 3 مرات (1978، 1986، 2022)',
                    'facts'=>['Diego Maradona\'s "Hand of God" and "Goal of the Century" both came in 1986.','Lionel Messi won the 2022 Golden Ball, captaining Argentina to glory.'],
                    'factsAr'=>['"يد الله" و"هدف القرن" لمارادونا جاءا معاً في 1986.','فاز ميسي بالكرة الذهبية 2022 وقاد الأرجنتين للمجد.']],
                'br' => ['wc'=>'5-time champions — a record','wcAr'=>'بطل 5 مرات — رقم قياسي',
                    'facts'=>['The only nation to play in every World Cup finals.','Pelé is the only player with three World Cup winner\'s medals.'],
                    'factsAr'=>['المنتخب الوحيد الذي شارك في كل نسخ كأس العالم.','بيليه اللاعب الوحيد الحائز على ثلاث ميداليات بطولة عالمية.']],
                'uy' => ['wc'=>'2-time champions (1930, 1950)','wcAr'=>'بطل مرتين (1930، 1950)',
                    'facts'=>['Won the first-ever World Cup as hosts in 1930.','The 1950 "Maracanazo" silenced 200,000 fans in Brazil.'],
                    'factsAr'=>['فاز بأول كأس عالم على أرضه 1930.','"ماراكانازو" 1950 أسكت 200 ألف مشجع في البرازيل.']],
                'co' => ['wc'=>'6 appearances · Best: Quarter-finals (2014)','wcAr'=>'6 مشاركات · الأفضل: ربع النهائي (2014)',
                    'facts'=>['James Rodríguez won the 2014 Golden Boot with 6 goals.','Carlos Valderrama defined Colombian flair in the 1990s.'],
                    'factsAr'=>['فاز خاميس رودريغيز بالحذاء الذهبي 2014 بـ6 أهداف.','كارلوس فالديراما جسّد مهارة كولومبيا في التسعينيات.']],
                'ec' => ['wc'=>'4 appearances · Best: Round of 16 (2006)','wcAr'=>'4 مشاركات · الأفضل: دور الـ16 (2006)',
                    'facts'=>['Quito sits at 2,850m — among the toughest places to play.'],
                    'factsAr'=>['تقع كيتو على ارتفاع 2850م — من أصعب الملاعب على الخصوم.']],
                'py' => ['wc'=>'8 appearances · Best: Quarter-finals (2010)','wcAr'=>'8 مشاركات · الأفضل: ربع النهائي (2010)',
                    'facts'=>['Goalkeeper José Luis Chilavert scored 8 international goals.'],
                    'factsAr'=>['الحارس خوسيه لويس تشيلافيرت سجّل 8 أهداف دولية.']],
                've' => ['wc'=>'Yet to reach a World Cup','wcAr'=>'لم يبلغ كأس العالم بعد',
                    'facts'=>['The only CONMEBOL side never to play a World Cup — chasing a first.'],
                    'factsAr'=>['المنتخب الوحيد في كونميبول الذي لم يشارك في مونديال — يطارد أول تأهل.']],

                /* ---- UEFA ---- */
                'fr' => ['wc'=>'2-time champions (1998, 2018) · 2022 finalists','wcAr'=>'بطل مرتين (1998، 2018) · وصيف 2022',
                    'facts'=>['Won its first World Cup as host in 1998.','Kylian Mbappé scored a hat-trick in the 2022 final and still lost.'],
                    'factsAr'=>['فاز بأول لقب على أرضه 1998.','سجّل مبابي هاتريك في نهائي 2022 ومع ذلك خسر.']],
                'es' => ['wc'=>'Champions (2010)','wcAr'=>'بطل (2010)',
                    'facts'=>['Won 2010 as the first team to lift the trophy after losing its opener.','Completed the Euro-World Cup-Euro treble of 2008-2012.'],
                    'factsAr'=>['فاز 2010 كأول منتخب يتوّج بعد خسارة مباراته الأولى.','حقق ثلاثية يورو-مونديال-يورو 2008-2012.']],
                'de' => ['wc'=>'4-time champions (1954, 1974, 1990, 2014)','wcAr'=>'بطل 4 مرات (1954، 1974، 1990، 2014)',
                    'facts'=>['Holds the record for most World Cup final appearances (8).','Miroslav Klose is the World Cup\'s all-time top scorer (16).'],
                    'factsAr'=>['يملك الرقم القياسي لأكثر مشاركات في النهائي (8).','ميروسلاف كلوزه الهداف التاريخي لكأس العالم (16 هدفاً).']],
                'pt' => ['wc'=>'8 appearances · Best: 3rd place (1966)','wcAr'=>'8 مشاركات · الأفضل: المركز الثالث (1966)',
                    'facts'=>['Eusébio top-scored with 9 goals at the 1966 World Cup.','Cristiano Ronaldo has scored at a record five different World Cups.'],
                    'factsAr'=>['أوزيبيو احتل صدارة الهدافين بـ9 أهداف في مونديال 1966.','رونالدو سجّل في خمس نسخ مختلفة من كأس العالم — رقم قياسي.']],
                'gb-eng' => ['wc'=>'Champions (1966)','wcAr'=>'بطل (1966)',
                    'facts'=>['Won its only World Cup at home in 1966.','Geoff Hurst is the only man to score a hat-trick in a World Cup final.'],
                    'factsAr'=>['فاز بلقبه الوحيد على أرضه 1966.','جيف هيرست الوحيد الذي سجّل هاتريك في نهائي كأس العالم.']],
                'nl' => ['wc'=>'3-time finalists (1974, 1978, 2010)','wcAr'=>'وصيف 3 مرات (1974، 1978، 2010)',
                    'facts'=>['The greatest side never to win a World Cup.','Johan Cruyff\'s "Total Football" reshaped the modern game.'],
                    'factsAr'=>['أعظم منتخب لم يفز بكأس العالم.','"الكرة الشاملة" لكرويف أعادت تشكيل اللعبة الحديثة.']],
                'be' => ['wc'=>'14 appearances · Best: 3rd place (2018)','wcAr'=>'14 مشاركة · الأفضل: المركز الثالث (2018)',
                    'facts'=>['Its "golden generation" topped the FIFA ranking 2018-2022.'],
                    'factsAr'=>['تصدّر "الجيل الذهبي" تصنيف الفيفا 2018-2022.']],
                'it' => ['wc'=>'4-time champions (1934, 1938, 1982, 2006)','wcAr'=>'بطل 4 مرات (1934، 1938، 1982، 2006)',
                    'facts'=>['Second only to Brazil in World Cup titles.','Won 2006 weeks after a domestic match-fixing scandal.'],
                    'factsAr'=>['الثاني بعد البرازيل في عدد ألقاب كأس العالم.','فاز 2006 بعد أسابيع من فضيحة تلاعب بالنتائج محلياً.']],
                'hr' => ['wc'=>'6 appearances · 2018 finalists · 3rd (2022)','wcAr'=>'6 مشاركات · وصيف 2018 · ثالث 2022',
                    'facts'=>['Reached the 2018 final in only its second appearance as an independent nation.','Luka Modrić won the 2018 Golden Ball.'],
                    'factsAr'=>['بلغ نهائي 2018 في ثاني مشاركة له كدولة مستقلة.','فاز لوكا مودريتش بالكرة الذهبية 2018.']],
                'ch' => ['wc'=>'12 appearances · Best: Quarter-finals (1934, 1938, 1954)','wcAr'=>'12 مشاركة · الأفضل: ربع النهائي (1934، 1938، 1954)',
                    'facts'=>['Knocked out world champions France on penalties at Euro 2020.'],
                    'factsAr'=>['أقصى بطل العالم فرنسا بركلات الترجيح في يورو 2020.']],
                'dk' => ['wc'=>'6 appearances · Best: Quarter-finals (1998)','wcAr'=>'6 مشاركات · الأفضل: ربع النهائي (1998)',
                    'facts'=>['Won Euro 1992 despite only qualifying as a late replacement.'],
                    'factsAr'=>['فاز بيورو 1992 رغم تأهله كبديل في اللحظات الأخيرة.']],
                'rs' => ['wc'=>'13 appearances (as Yugoslavia/Serbia) · Best: 4th (1930, 1962)','wcAr'=>'13 مشاركة (يوغوسلافيا/صربيا) · الأفضل: الرابع (1930، 1962)',
                    'facts'=>['Yugoslavia were World Cup semi-finalists in 1930 and 1962.'],
                    'factsAr'=>['بلغت يوغوسلافيا نصف النهائي في 1930 و1962.']],
                'tr' => ['wc'=>'2 appearances · Best: 3rd place (2002)','wcAr'=>'مشاركتان · الأفضل: المركز الثالث (2002)',
                    'facts'=>['Hakan Şükür scored the fastest goal in World Cup history (11 sec, 2002).'],
                    'factsAr'=>['سجّل هاكان شوكور أسرع هدف في تاريخ كأس العالم (11 ثانية، 2002).']],
                'at' => ['wc'=>'7 appearances · Best: 3rd place (1954)','wcAr'=>'7 مشاركات · الأفضل: المركز الثالث (1954)',
                    'facts'=>['The 1930s "Wunderteam" was one of football\'s first great sides.'],
                    'factsAr'=>['"الفريق العجيب" في الثلاثينيات من أوائل المنتخبات العظيمة.']],
                'no' => ['wc'=>'3 appearances · Best: Round of 16 (1998)','wcAr'=>'3 مشاركات · الأفضل: دور الـ16 (1998)',
                    'facts'=>['Norway famously never lost to Brazil (W2 D2) before 2024.'],
                    'factsAr'=>['لم تخسر النرويج أمام البرازيل (فوزان وتعادلان) حتى 2024.']],
                'gb-wls' => ['wc'=>'2 appearances · Best: Quarter-finals (1958)','wcAr'=>'مشاركتان · الأفضل: ربع النهائي (1958)',
                    'facts'=>['Its 2022 appearance ended a 64-year World Cup absence.'],
                    'factsAr'=>['أنهت مشاركتها 2022 غياباً عن المونديال دام 64 عاماً.']],
                'pl' => ['wc'=>'9 appearances · Best: 3rd place (1974, 1982)','wcAr'=>'9 مشاركات · الأفضل: المركز الثالث (1974، 1982)',
                    'facts'=>['Grzegorz Lato won the 1974 Golden Boot with 7 goals.'],
                    'factsAr'=>['فاز غجيغوج لاتو بالحذاء الذهبي 1974 بـ7 أهداف.']],

                /* ---- AFC ---- */
                'jp' => ['wc'=>'7 appearances · Best: Round of 16 (×4)','wcAr'=>'7 مشاركات · الأفضل: دور الـ16 (×4)',
                    'facts'=>['Beat both Germany and Spain in the 2022 group stage.','Japanese fans are famed for cleaning the stadium after matches.'],
                    'factsAr'=>['فاز على ألمانيا وإسبانيا في دور المجموعات 2022.','يشتهر الجمهور الياباني بتنظيف المدرجات بعد المباريات.']],
                'kr' => ['wc'=>'11 appearances · Best: 4th place (2002)','wcAr'=>'11 مشاركة · الأفضل: المركز الرابع (2002)',
                    'facts'=>['First Asian nation to reach a World Cup semi-final (2002, as co-hosts).'],
                    'factsAr'=>['أول منتخب آسيوي يبلغ نصف نهائي المونديال (2002، كمستضيف مشارك).']],
                'au' => ['wc'=>'6 appearances · Best: Round of 16 (2006, 2022)','wcAr'=>'6 مشاركات · الأفضل: دور الـ16 (2006، 2022)',
                    'facts'=>['Switched from Oceania to the Asian confederation in 2006.'],
                    'factsAr'=>['انتقلت من اتحاد أوقيانوسيا إلى الاتحاد الآسيوي عام 2006.']],
                'sa' => ['wc'=>'6 appearances · Best: Round of 16 (1994)','wcAr'=>'6 مشاركات · الأفضل: دور الـ16 (1994)',
                    'facts'=>['Saeed Al-Owairan\'s 1994 solo goal is a World Cup classic.','Beat eventual champions Argentina 2-1 in 2022.'],
                    'factsAr'=>['هدف سعيد العويران الفردي 1994 من كلاسيكيات المونديال.','فازت على بطل النسخة الأرجنتين 2-1 في 2022.']],
                'qa' => ['wc'=>'1 appearance (2022, hosts)','wcAr'=>'مشاركة واحدة (2022، مستضيف)',
                    'facts'=>['Hosted the first World Cup in the Middle East in 2022.','Back-to-back Asian Cup champions (2019, 2023).'],
                    'factsAr'=>['استضافت أول كأس عالم في الشرق الأوسط 2022.','بطل كأس آسيا مرتين متتاليتين (2019، 2023).']],
                'ir' => ['wc'=>'6 appearances · Group stage best','wcAr'=>'6 مشاركات · الأفضل: دور المجموعات',
                    'facts'=>['Beat the USA 2-1 in a politically charged 1998 match.'],
                    'factsAr'=>['فازت على الولايات المتحدة 2-1 في مباراة 1998 المشحونة سياسياً.']],
                'uz' => ['wc'=>'1st appearance (2026)','wcAr'=>'أول مشاركة (2026)',
                    'facts'=>['Qualified for its first-ever World Cup for 2026.'],
                    'factsAr'=>['تأهلت لأول كأس عالم في تاريخها 2026.']],
                'jo' => ['wc'=>'1st appearance (2026)','wcAr'=>'أول مشاركة (2026)',
                    'facts'=>['Reached the 2023 Asian Cup final, then qualified for a first World Cup.'],
                    'factsAr'=>['بلغ نهائي كأس آسيا 2023 ثم تأهل لأول مونديال.']],

                /* ---- CAF ---- */
                'ma' => ['wc'=>'6 appearances · Best: 4th place (2022)','wcAr'=>'6 مشاركات · الأفضل: المركز الرابع (2022)',
                    'facts'=>['First African and Arab nation to reach a World Cup semi-final (2022).','First African team to top its group in 1986.'],
                    'factsAr'=>['أول منتخب عربي وإفريقي يبلغ نصف نهائي كأس العالم (2022).','أول منتخب إفريقي يتصدّر مجموعته 1986.']],
                'sn' => ['wc'=>'3 appearances · Best: Quarter-finals (2002)','wcAr'=>'3 مشاركات · الأفضل: ربع النهائي (2002)',
                    'facts'=>['Beat holders France on its 2002 World Cup debut.'],
                    'factsAr'=>['فازت على حامل اللقب فرنسا في أول ظهور مونديالي 2002.']],
                'gh' => ['wc'=>'4 appearances · Best: Quarter-finals (2010)','wcAr'=>'4 مشاركات · الأفضل: ربع النهائي (2010)',
                    'facts'=>['Came within a Suárez handball of the 2010 semi-finals.'],
                    'factsAr'=>['كانت على بُعد لمسة يد من سواريز عن نصف نهائي 2010.']],
                'ng' => ['wc'=>'6 appearances · Best: Round of 16 (1994, 1998, 2014)','wcAr'=>'6 مشاركات · الأفضل: دور الـ16 (1994، 1998، 2014)',
                    'facts'=>['Won Olympic football gold in 1996, beating Argentina and Brazil.'],
                    'factsAr'=>['فازت بذهبية الأولمبياد 1996 بتغلّبها على الأرجنتين والبرازيل.']],
                'cm' => ['wc'=>'8 appearances · Best: Quarter-finals (1990)','wcAr'=>'8 مشاركات · الأفضل: ربع النهائي (1990)',
                    'facts'=>['Roger Milla\'s corner-flag dance lit up Italia 90.','First African side to reach the World Cup quarter-finals.'],
                    'factsAr'=>['رقصة روجيه ميلا عند ركن الملعب أضاءت مونديال 1990.','أول منتخب إفريقي يبلغ ربع نهائي كأس العالم.']],
                'eg' => ['wc'=>'3 appearances · First African side at a World Cup (1934)','wcAr'=>'3 مشاركات · أول منتخب إفريقي بالمونديال (1934)',
                    'facts'=>['Record 7-time Africa Cup of Nations champions.'],
                    'factsAr'=>['بطل كأس أمم إفريقيا 7 مرات — رقم قياسي.']],
                'dz' => ['wc'=>'4 appearances · Best: Round of 16 (2014)','wcAr'=>'4 مشاركات · الأفضل: دور الـ16 (2014)',
                    'facts'=>['Beat West Germany in 1982 — the famous "Disgrace of Gijón" followed.'],
                    'factsAr'=>['فازت على ألمانيا الغربية 1982 — وتلتها "فضيحة خيخون" الشهيرة.']],
                'tn' => ['wc'=>'6 appearances · First African World Cup win (1978)','wcAr'=>'6 مشاركات · أول فوز إفريقي بالمونديال (1978)',
                    'facts'=>['Beat Mexico in 1978 for the first World Cup win by an African team.','Beat champions France in the 2022 group stage.'],
                    'factsAr'=>['فازت على المكسيك 1978 في أول فوز إفريقي بالمونديال.','فازت على بطل النسخة فرنسا في دور المجموعات 2022.']],
                'ci' => ['wc'=>'3 appearances · Group stage best','wcAr'=>'3 مشاركات · الأفضل: دور المجموعات',
                    'facts'=>['Didier Drogba\'s appeal helped pause a civil war in 2005.','Home winners of the 2023 Africa Cup of Nations.'],
                    'factsAr'=>['نداء دروغبا ساهم في وقف حرب أهلية 2005.','بطل كأس أمم إفريقيا 2023 على أرضه.']],

                /* ---- OFC ---- */
                'nz' => ['wc'=>'2 appearances · Unbeaten in 2010','wcAr'=>'مشاركتان · بلا خسارة 2010',
                    'facts'=>['The only unbeaten team at the 2010 World Cup — yet went out in the group.'],
                    'factsAr'=>['المنتخب الوحيد بلا خسارة في مونديال 2010 — ومع ذلك خرج من المجموعات.']],
            ];
        }
        if ($code === '') return $f;
        $code = strtolower(trim($code));
        return $f[$code] ?? [];
    }
}
