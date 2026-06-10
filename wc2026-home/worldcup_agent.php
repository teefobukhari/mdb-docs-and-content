<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| World Cup 2026 Fan Agent - AR / EN  (Improved UI)
|--------------------------------------------------------------------------
| Path: /var/www/html/AI-Gateway/worldcup_agent.php
| Calls existing AI Gateway endpoints. Sends `text` (required by _common.php).
| Improvements: CATRION/Saudi theme toggle, glass UI, animated hero,
| chat-style output, quick skills, bilingual (EN/AR + RTL), persisted prefs.
*/
?>
<!doctype html>
<html lang="en" dir="ltr" data-theme="catrion">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>World Cup 2026 Fan Agent</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#05162F; --brand2:#08234A; --brand3:#0E63E6;
  --accent:#F5C85B; --accent2:#FFE19A; --good:#22C55E;
  --panel:rgba(7,31,66,.92); --line:rgba(168,231,255,.18);
  --text:#EAF3FF; --muted:rgba(234,243,255,.66);
  --shadow:0 26px 64px rgba(0,0,0,.4);
}
html[data-theme="saudi"]{
  --brand:#03190f; --brand2:#06371f; --brand3:#0e7c43; --accent:#FFE19A; --accent2:#F5C85B;
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:'Inter','Tajawal',sans-serif;color:var(--text);
  background:radial-gradient(circle at 10% 6%,rgba(85,183,255,.12),transparent 30%),
    radial-gradient(circle at 92% 4%,rgba(14,99,230,.20),transparent 34%),
    linear-gradient(180deg,var(--brand) 0%,var(--brand2) 50%,var(--brand) 100%);background-attachment:fixed}
html[dir="rtl"] body{font-family:'Tajawal','Inter',sans-serif}
.app{max-width:1180px;margin:0 auto;padding:24px}
.hero{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--brand) 0%,var(--brand2) 55%,var(--brand3) 100%);
  color:#fff;border-radius:26px;padding:26px;box-shadow:var(--shadow);margin-bottom:18px;
  display:flex;justify-content:space-between;gap:18px;align-items:flex-start;border:1px solid var(--line)}
.hero:after{content:"";position:absolute;width:320px;height:320px;border-radius:50%;top:-130px;inset-inline-end:-90px;
  background:radial-gradient(circle,rgba(245,200,91,.30),transparent 70%);pointer-events:none}
.hero-l{position:relative;z-index:1}
.hero h1{margin:0;font-size:30px;font-weight:900;line-height:1.15}
.hero h1 b{color:var(--accent)}
.hero p{margin:10px 0 0;color:rgba(255,255,255,.88);font-size:14px;line-height:1.7;max-width:560px}
.hero-r{position:relative;z-index:1;display:flex;flex-direction:column;gap:10px;align-items:flex-end}
.switch{display:flex;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:5px}
.switch button{border:0;border-radius:999px;padding:8px 13px;background:transparent;color:#fff;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit}
.switch button.active{background:#fff;color:var(--brand)}
.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:14px;box-shadow:var(--shadow)}
.card .label{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:800}
.card .value{margin-top:6px;font-size:14px;font-weight:800;color:#fff}
.grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);overflow:hidden}
.panel-head{padding:16px 18px;background:linear-gradient(135deg,rgba(245,200,91,.16),rgba(85,183,255,.12));border-bottom:1px solid var(--line)}
.panel-head h2{margin:0;font-size:16px;font-weight:900}
.panel-head p{margin:5px 0 0;font-size:12px;color:var(--muted)}
.panel-body{padding:18px}
.field{margin-bottom:14px}
.field label{display:block;margin-bottom:7px;font-size:12px;font-weight:800;color:var(--accent)}
.field input,.field select,.field textarea{width:100%;border:1px solid var(--line);border-radius:14px;padding:12px 13px;
  font:inherit;font-size:13px;color:#fff;background:rgba(255,255,255,.06);outline:none}
.field option{color:#06202e}
.field textarea{min-height:120px;resize:vertical;line-height:1.7}
.btn{width:100%;min-height:46px;border:0;border-radius:14px;padding:0 16px;font-weight:900;font-size:13px;cursor:pointer;font-family:inherit;
  background:linear-gradient(135deg,var(--accent),var(--accent2));color:#06202e}
.btn.secondary{background:rgba(255,255,255,.07);color:#fff;border:1px solid var(--line)}
.btn-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.quick{display:grid;gap:8px;margin-top:14px}
.quick button{border:1px solid var(--line);background:rgba(255,255,255,.05);color:#fff;border-radius:12px;padding:10px 12px;
  text-align:start;font-weight:700;cursor:pointer;font-size:12px;font-family:inherit}
.quick button:hover{border-color:var(--accent);color:var(--accent)}
.status{margin-top:12px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.05);font-size:12px;color:var(--muted)}
.chat{min-height:560px;display:flex;flex-direction:column;gap:12px}
.msg{max-width:88%;padding:12px 15px;border-radius:16px;line-height:1.85;font-size:14px;white-space:pre-wrap;word-wrap:break-word}
.msg.bot{align-self:flex-start;background:rgba(255,255,255,.07);border:1px solid var(--line);border-bottom-left-radius:5px}
.msg.user{align-self:flex-end;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#06202e;font-weight:700;border-bottom-right-radius:5px}
html[dir="rtl"] .msg.user{border-bottom-right-radius:16px;border-bottom-left-radius:5px}
html[dir="rtl"] .msg.bot{border-bottom-left-radius:16px;border-bottom-right-radius:5px}
.typing{align-self:flex-start;display:flex;gap:5px;padding:13px 15px;background:rgba(255,255,255,.07);border:1px solid var(--line);border-radius:16px}
.typing i{width:7px;height:7px;border-radius:50%;background:var(--accent);animation:t 1.2s infinite}
.typing i:nth-child(2){animation-delay:.2s}.typing i:nth-child(3){animation-delay:.4s}
@keyframes t{0%{opacity:.25}20%{opacity:1}100%{opacity:.25}}
.raw{margin-top:16px;border-top:1px solid var(--line);padding-top:14px}
.raw summary{cursor:pointer;font-weight:800;color:var(--accent);font-size:12px}
.raw pre{background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:14px;padding:12px;overflow:auto;max-height:240px;font-size:11px;direction:ltr;text-align:left}
@media(max-width:960px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}.hero{flex-direction:column}.hero-r{align-items:flex-start;flex-direction:row;flex-wrap:wrap}}
@media(max-width:600px){.app{padding:14px}.cards{grid-template-columns:1fr}.btn-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app">
  <section class="hero">
    <div class="hero-l">
      <h1 data-i18n-html="title">World Cup 2026 <b>Fan Agent</b></h1>
      <p data-i18n="subtitle">AI-powered fan assistant connected to API-Football and Azure OpenAI through your central AI Gateway.</p>
    </div>
    <div class="hero-r">
      <div class="switch" aria-label="Theme">
        <button type="button" data-theme-set="catrion" data-i18n="themeCatrion">CATRION</button>
        <button type="button" data-theme-set="saudi" data-i18n="themeSaudi">Saudi</button>
      </div>
      <div class="switch" aria-label="Language">
        <button type="button" data-lang-set="en">EN</button>
        <button type="button" data-lang-set="ar">عربي</button>
      </div>
    </div>
  </section>

  <div class="cards">
    <div class="card"><div class="label" data-i18n="cardModule">Module</div><div class="value">WORLDCUP</div></div>
    <div class="card"><div class="label" data-i18n="cardModeLabel">Mode</div><div class="value" id="cardMode">Fan Assistant</div></div>
    <div class="card"><div class="label" data-i18n="cardDateLabel">Date</div><div class="value" id="cardDate">-</div></div>
    <div class="card"><div class="label" data-i18n="cardFixtureLabel">Fixture</div><div class="value" id="cardFixture">-</div></div>
    <div class="card"><div class="label" data-i18n="cardTeamLabel">Team</div><div class="value" id="cardTeam">-</div></div>
  </div>

  <div class="grid">
    <section class="panel">
      <div class="panel-head"><h2 data-i18n="controlTitle">Agent Control</h2><p data-i18n="controlSub">Choose the AI skill and provide date, fixture ID, team ID, or question.</p></div>
      <div class="panel-body">
        <div class="field"><label data-i18n="functionLabel">AI Function</label>
          <select id="mode">
            <option value="fan" data-i18n="fan">Fan Assistant</option>
            <option value="predict" data-i18n="predict">Match Predictor</option>
            <option value="tactical" data-i18n="tactical">Tactical Analyst</option>
            <option value="summary" data-i18n="summary">Match Summary</option>
            <option value="command" data-i18n="command">Command Center</option>
          </select></div>
        <div class="field"><label data-i18n="dateLabel">Date</label><input id="date" type="date" value="2026-06-11"></div>
        <div class="field"><label data-i18n="fixtureLabel">Fixture ID (optional)</label><input id="fixture" type="number" placeholder="e.g. 123456"></div>
        <div class="field"><label data-i18n="teamLabel">Team ID (optional)</label><input id="team" type="number" placeholder="API-Football team ID"></div>
        <div class="field"><label data-i18n="questionLabel">Question / Instruction</label><textarea id="question">What should Saudi fans watch today?</textarea></div>
        <div class="btn-row">
          <button class="btn" type="button" id="runBtn" data-i18n="runBtn">Run Agent</button>
          <button class="btn secondary" type="button" id="clearBtn" data-i18n="clearBtn">Clear</button>
        </div>
        <div class="quick">
          <button type="button" data-quick="fan|daily" data-i18n="quickDaily">Saudi fan daily briefing</button>
          <button type="button" data-quick="predict|predict" data-i18n="quickPredict">Predict fixture</button>
          <button type="button" data-quick="tactical|tactical" data-i18n="quickTactical">Tactical analysis</button>
          <button type="button" data-quick="summary|summary" data-i18n="quickSummary">Match summary</button>
          <button type="button" data-quick="command|command" data-i18n="quickCommand">Command center</button>
        </div>
        <div class="status" id="status" data-i18n="ready">Ready.</div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2 data-i18n="outputTitle">Agent Output</h2><p data-i18n="outputSub">Response from Azure OpenAI using API-Football context.</p></div>
      <div class="panel-body">
        <div class="chat" id="chat"></div>
        <details class="raw"><summary data-i18n="rawJson">Raw JSON</summary><pre id="rawJson">{}</pre></details>
      </div>
    </section>
  </div>
</div>

<script>
const endpointMap={
  fan:'/AI-Gateway/api/wc_ai_fan_assistant.php',
  predict:'/AI-Gateway/api/wc_ai_predict.php',
  tactical:'/AI-Gateway/api/wc_ai_tactical.php',
  summary:'/AI-Gateway/api/wc_ai_match_summary.php',
  command:'/AI-Gateway/api/wc_ai_command_center.php'
};
const i18n={
 en:{title:'World Cup 2026 <b>Fan Agent</b>',subtitle:'AI-powered fan assistant connected to API-Football and Azure OpenAI through your central AI Gateway.',
  themeCatrion:'CATRION',themeSaudi:'Saudi',cardModule:'Module',cardModeLabel:'Mode',cardDateLabel:'Date',cardFixtureLabel:'Fixture',cardTeamLabel:'Team',
  controlTitle:'Agent Control',controlSub:'Choose the AI skill and provide date, fixture ID, team ID, or question.',
  functionLabel:'AI Function',dateLabel:'Date',fixtureLabel:'Fixture ID (optional)',teamLabel:'Team ID (optional)',questionLabel:'Question / Instruction',
  runBtn:'Run Agent',clearBtn:'Clear',quickDaily:'Saudi fan daily briefing',quickPredict:'Predict fixture',quickTactical:'Tactical analysis',quickSummary:'Match summary',quickCommand:'Command center',
  ready:'Ready.',outputTitle:'Agent Output',outputSub:'Response from Azure OpenAI using API-Football context.',rawJson:'Raw JSON',
  running:'Running agent…',failed:'Failed',done:'Done',error:'Error',nonJson:'Failed: non-JSON response',greeting:'Welcome! Pick a skill, set the details, and run the agent.',
  fan:'Fan Assistant',predict:'Match Predictor',tactical:'Tactical Analyst',summary:'Match Summary',command:'Command Center',
  quickText:{daily:'What should Saudi fans watch today?',predict:'Predict this fixture and explain confidence clearly.',tactical:'Analyze the tactical strengths, weaknesses, and key matchups.',summary:'Summarize this match for social media and fans.',command:'Give executive dashboard insights for today.'},
  aiInstruction:'Respond in English. Use clear fan-friendly wording. If live data is missing, say what is missing.'},
 ar:{title:'وكيل جماهير <b>كأس العالم 2026</b>',subtitle:'مساعد ذكي للجماهير مرتبط ببيانات API-Football و Azure OpenAI عبر بوابة الذكاء الاصطناعي المركزية.',
  themeCatrion:'كاتريون',themeSaudi:'السعودية',cardModule:'الوحدة',cardModeLabel:'النمط',cardDateLabel:'التاريخ',cardFixtureLabel:'المباراة',cardTeamLabel:'الفريق',
  controlTitle:'لوحة التحكم',controlSub:'اختر وظيفة الذكاء الاصطناعي وأدخل التاريخ أو رقم المباراة أو رقم الفريق أو السؤال.',
  functionLabel:'وظيفة الذكاء الاصطناعي',dateLabel:'التاريخ',fixtureLabel:'رقم المباراة (اختياري)',teamLabel:'رقم الفريق (اختياري)',questionLabel:'السؤال / التعليمات',
  runBtn:'تشغيل الوكيل',clearBtn:'مسح',quickDaily:'موجز يومي للمشجع السعودي',quickPredict:'توقع المباراة',quickTactical:'تحليل تكتيكي',quickSummary:'ملخص المباراة',quickCommand:'مركز القيادة',
  ready:'جاهز.',outputTitle:'مخرجات الوكيل',outputSub:'استجابة من Azure OpenAI باستخدام سياق API-Football.',rawJson:'JSON الخام',running:'جاري التشغيل…',failed:'فشل',done:'تم',error:'خطأ',nonJson:'فشل: الاستجابة ليست JSON',greeting:'مرحبًا! اختر مهارة، حدّد التفاصيل، ثم شغّل الوكيل.',
  fan:'مساعد الجماهير',predict:'متوقّع المباراة',tactical:'محلل تكتيكي',summary:'ملخص المباراة',command:'مركز القيادة',
  quickText:{daily:'ما أهم ما يتابعه المشجع السعودي اليوم؟',predict:'توقّع نتيجة هذه المباراة واشرح مستوى الثقة بوضوح.',tactical:'حلل نقاط القوة والضعف التكتيكية والمواجهات المهمة.',summary:'لخص هذه المباراة للجماهير ووسائل التواصل.',command:'أعطني رؤى تنفيذية ولوحة قيادة لليوم.'},
  aiInstruction:'أجب بالعربية بأسلوب واضح ومناسب للجماهير. إذا كانت البيانات الحية غير متوفرة فاذكر ذلك بوضوح.'}
};
let lang=localStorage.getItem('wc_agent_lang')||'en';
let theme=localStorage.getItem('wc_agent_theme')||'catrion';
function t(k){return (i18n[lang]&&i18n[lang][k]!=null)?i18n[lang][k]:(i18n.en[k]!=null?i18n.en[k]:k);}
const $=id=>document.getElementById(id);
function setTheme(x){theme=(x==='saudi')?'saudi':'catrion';document.documentElement.setAttribute('data-theme',theme);
  document.querySelectorAll('[data-theme-set]').forEach(b=>b.classList.toggle('active',b.dataset.themeSet===theme));localStorage.setItem('wc_agent_theme',theme);}
function applyLang(l){lang=(l==='ar')?'ar':'en';document.documentElement.lang=lang;document.documentElement.dir=lang==='ar'?'rtl':'ltr';
  document.querySelectorAll('[data-i18n]').forEach(el=>el.textContent=t(el.dataset.i18n));
  document.querySelectorAll('[data-i18n-html]').forEach(el=>el.innerHTML=t(el.dataset.i18nHtml));
  document.querySelectorAll('[data-lang-set]').forEach(b=>b.classList.toggle('active',b.dataset.langSet===lang));
  localStorage.setItem('wc_agent_lang',lang);syncCards();
  if(!$('chat').children.length) addMsg(t('greeting'),'bot');}
function modeLabel(m){return t(m)||m;}
function addMsg(text,who){const m=document.createElement('div');m.className='msg '+who;m.textContent=text;$('chat').appendChild(m);$('chat').scrollTop=$('chat').scrollHeight;return m;}
function syncCards(){$('cardMode').textContent=modeLabel($('mode').value);$('cardDate').textContent=$('date').value||'-';$('cardFixture').textContent=$('fixture').value||'-';$('cardTeam').textContent=$('team').value||'-';}
function clearOutput(){$('chat').innerHTML='';$('rawJson').textContent='{}';$('status').textContent=t('ready');addMsg(t('greeting'),'bot');}
async function runAgent(){
  syncCards();
  const mode=$('mode').value,endpoint=endpointMap[mode];
  const q=$('question').value||'';
  addMsg(q||modeLabel(mode),'user');
  const payload={module:'WORLDCUP',lang,date:$('date').value,text:t('aiInstruction')+"\n\nUser request:\n"+q};
  if($('fixture').value) payload.fixture=parseInt($('fixture').value,10);
  if($('team').value) payload.team=parseInt($('team').value,10);
  $('status').textContent=t('running');
  const typing=document.createElement('div');typing.className='typing';typing.innerHTML='<i></i><i></i><i></i>';$('chat').appendChild(typing);$('chat').scrollTop=$('chat').scrollHeight;
  try{
    const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const raw=await res.text();typing.remove();
    let data;try{data=JSON.parse(raw);}catch(e){$('status').textContent=t('nonJson');addMsg(raw,'bot');$('rawJson').textContent=raw;return;}
    $('rawJson').textContent=JSON.stringify(data,null,2);
    if(data && data.ok===false){$('status').textContent=t('failed');addMsg(data.error||'Unknown error','bot');return;}
    $('status').textContent=t('done');addMsg((data&&(data.result||data.output))||JSON.stringify(data,null,2),'bot');
  }catch(e){typing.remove();$('status').textContent=t('error');addMsg(e.message,'bot');}
}
document.querySelectorAll('[data-theme-set]').forEach(b=>b.addEventListener('click',()=>setTheme(b.dataset.themeSet)));
document.querySelectorAll('[data-lang-set]').forEach(b=>b.addEventListener('click',()=>applyLang(b.dataset.langSet)));
document.querySelectorAll('[data-quick]').forEach(b=>b.addEventListener('click',()=>{const[m,k]=b.dataset.quick.split('|');$('mode').value=m;$('question').value=i18n[lang].quickText[k]||'';syncCards();}));
$('runBtn').addEventListener('click',runAgent);
$('clearBtn').addEventListener('click',clearOutput);
['mode','date','fixture','team'].forEach(id=>$(id).addEventListener('change',syncCards));
setTheme(theme);applyLang(lang);
</script>
</body>
</html>
