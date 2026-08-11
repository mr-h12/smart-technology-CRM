<div dir="rtl">

# النسخة العربية — Smart Technology CRM

الفولدر ده فيه ترجمة عربية للملفات اللي اتبنت في المشروع، مكتوبة باللهجة المصرية.

## ⚠️ مهم تعرفه الأول

**الملفات الإنجليزية هي المرجع الرسمي، مش دي.** لو لقيت اختلاف بين ملف هنا وملف إنجليزي، الإنجليزي هو الصح والعربي محتاج يتظبط. ده مش تقليل من قيمة الترجمة — ده علشان المستندات نفسها بتقول إن `docs/CRM_Documentation_EN.md` هو المصدر الأعلى، وأي حاجة تانية بتيجي بعده.

**الملفات اللي في `arabic/agents/` و `arabic/skills/` للقراءة بس.** الإعدادات الشغالة فعليًا هي اللي في `.claude/` بالإنجليزي. لو عدّلت في النسخة العربية مش هيحصل حاجة — الأدوات مش بتقراها.

## اللي موجود هنا

| الملف | الأصل | بيتكلم عن إيه |
|---|---|---|
| [نظرة عامة على المشروع](project-overview.md) | `README.md` | المشروع بيعمل إيه، والترتيب، والقواعد اللي سهل تتكسر |
| [قائمة المتابعة](CHECKLIST_AR.md) | `CHECKLIST.md` | كل موديول ومعاييره — ده ملف الشغل اليومي |
| [دليل Claude Code](CLAUDE_AR.md) | `CLAUDE.md` | تعليمات التنفيذ للوكيل |
| [عقد الوكلاء العام](AGENTS_AR.md) | `AGENTS.md` | نفس القواعد بس لأي وكيل تاني (Codex, Raflo) |
| [مراجع مصفوفة الصلاحيات](agents/permission-matrix-auditor_AR.md) | `.claude/agents/…` | بيراجع أي endpoint على مصفوفة الصلاحيات |
| [مراجع قواعد التسعير](agents/pricing-invariant-reviewer_AR.md) | `.claude/agents/…` | بيراجع أي كود بيلمس فلوس |
| [بدء موديول](skills/module-kickoff_AR.md) | `.claude/skills/…` | بيجهّز الموديول قبل ما تكتب كود |
| [مزامنة المستندات](skills/doc-sync_AR.md) | `.claude/skills/…` | بيتأكد إن الملفات التوأم متطابقة |
| [نموذج PDF العربي](prototypes/p01-arabic-pdf_AR.md) | `prototypes/…` | نموذج P-01 اللي أنهى مخاطرة `R-02` |

## حاجات مترجمناهاش عن قصد

- **أسماء الملفات** — `CLAUDE.md`, `docs/CRM_Documentation_EN.md`. لو ترجمناها الروابط هتقع.
- **أكواد القرارات** — `D-57`, `DB-07`, `OD-01`, `J-01`, `SEC-03`. دي وسيلة التتبع في المشروع كله.
- **أسماء الكود** — `SoftDeletes`, `rounding_diff`, `SearchService`, `decimal:`. دي أسماء فعلية في الكود.
- **حالات الصفقة** — `Lead`, `Won`, `Lost`, `Draft`, `Pending`. دي قيم مخزّنة في الداتابيز.
- **ملفات الكود نفسها** — `render.js`, `template.js`, `settings.json`. دي كود مش مستندات.

## المستندات السبعة الأصلية

المستندات الكبيرة اللي في `docs/` — دي **لسه محتاجة نسخة عربية** والمستندات نفسها بتطلبها بالاسم:

| الإنجليزي الموجود | العربي المطلوب | الحالة |
|---|---|---|
| `docs/CRM_Documentation_EN.md` | `CRM_Documentation.md` | ❌ مش موجود |
| `docs/MVP_Build_Plan_EN.md` | `MVP_Build_Plan.md` | ❌ مش موجود |
| `docs/Design_System_EN.md` | `Design_System_AR.md` | ❌ مش موجود |
| `docs/OpenAPI_Contract_EN.md` | `OpenAPI_Contract_AR.md` | ❌ مش موجود |
| `docs/Documentation_Map_EN.md` | `Documentation_Map_AR.md` | ❌ مش موجود |

دي حوالي 2900 سطر، وفيها أرقام ومعادلات مالية وقانونية دقيقة. محتاجة جلسة لوحدها ومراجعة منك بعدها.

</div>
