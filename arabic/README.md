<div dir="rtl">

# النسخة العربية — Smart Technology CRM

الفولدر ده فيه ترجمة عربية للملفات اللي اتبنت في المشروع، مكتوبة باللهجة المصرية.

## ⚠️ اقرا ده الأول

**كل اللي في الفولدر ده للقراءة بس. مش جزء من النظام وعمره ما هيتستخدم.**

- النظام كله بيشتغل على الملفات الإنجليزية في `docs/` و `.claude/` — دي المصادر الوحيدة.
- الملفات دي **مش بتتزامن** مع الإنجليزي. لو الإنجليزي اتغيّر، اللي هنا هيبقى قديم، وده مقبول.
- مفيش وكيل بيقراها. `/doc-sync` مش بيفحصها.
- لو عدّلت فيها، **مش هيحصل أي حاجة في النظام**.

يعني لو لقيت اختلاف بين ملف هنا وملف إنجليزي: **الإنجليزي هو الصح دايمًا**، والعربي مجرد صورة من لحظة معينة.

## اللي موجود هنا

| الملف | الأصل | بيتكلم عن إيه |
|---|---|---|
| [نظرة عامة على المشروع](project-overview.md) | `README.md` | المشروع بيعمل إيه، والترتيب، والقواعد اللي سهل تتكسر |
| [المستندات السبعة كاملة](docs/) | `docs/*_EN.md` | ترجمة المواصفات كلها — سجل القرارات، الصلاحيات، التسعير، المسارات |
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

## المواصفات السبعة — في `arabic/docs/`

| الملف | الأصل الإنجليزي |
|---|---|
| `CRM_Documentation.md` | `docs/CRM_Documentation_EN.md` |
| `MVP_Build_Plan.md` | `docs/MVP_Build_Plan_EN.md` |
| `OpenAPI_Contract_AR.md` | `docs/OpenAPI_Contract_EN.md` |
| `Design_System_AR.md` | `docs/Design_System_EN.md` |
| `Coding_Standards_AR.md` | `docs/Coding_Standards_EN.md` |
| `User_Personas_AR.md` | `docs/User_Personas_EN.md` |
| `Documentation_Map_AR.md` | `docs/Documentation_Map_EN.md` |

⚠️ فيها أرقام ونسب ضريبية ومعادلات. **متبنيش عليها قرار من غير ما ترجع للإنجليزي** — الترجمة للفهم، والإنجليزي للتنفيذ.

### ملاحظة مفتوحة

خمس مستندات إنجليزية لسه فيها سطر في الترويسة بيعلن نسخة عربية مطلوبة (مثلاً `CRM_Documentation_EN.md` سطر 6). السطور دي اتكتبت قبل ما نقرر إن العربي للقراءة بس، وبقت بتقول حاجة مش صح. تلاتة من الملفات دي محميّة بالـ hook، **فتعديل الترويسات محتاج قرار منك**.

</div>
