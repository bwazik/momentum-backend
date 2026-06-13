# لماذا اخترنا هذا النموذج للصلاحيات؟

## الهدف من هذا المستند

هذا المستند يشرح سبب اختيار نموذج صلاحيات مرن ومعقد نسبيا بدلا من نموذج أدوار بسيط.

الهدف ليس التعقيد من أجل التعقيد. الهدف أن تكون المنصة قابلة للاستخدام في أكثر من نوع مؤسسة:

- وزارة
- جهة شبه حكومية
- جامعة
- مستشفى
- شركة خاصة
- مؤسسة كبيرة متعددة الإدارات

كل جهة من هذه الجهات لديها مسميات وظيفية مختلفة، وتسلسل إداري مختلف، وطريقة مختلفة في المتابعة والاعتماد والرقابة.

لذلك لا يمكن أن نبني النظام على أدوار ثابتة مثل:

```text
Organization Executive
Department Director
Follow-Up Specialist
Stage Assignee
```

هذه أسماء مفيدة لفهم السيناريو الحكومي، لكنها لا تصلح كأدوار ثابتة داخل منتج SaaS قابل لإعادة الاستخدام.

---

## الخلاصة السريعة

النموذج الذي اخترناه هو:

```text
Policy-Based ABAC
+ Configurable Positions
+ Reusable Capabilities
+ Relationship-Based Access
```

بمعنى أبسط:

```text
نوع الحساب
+ المنصب داخل المؤسسة
+ درجة السلطة
+ الصلاحيات الممنوحة
+ نطاق الصلاحية
+ علاقة المستخدم بالمهمة
+ تصنيف المهمة
= قرار السماح أو المنع
```

هذا أقرب إلى نموذج ABAC/PBAC، لكنه ليس ABAC خام فقط، وليس RBAC تقليدي، وليس صلاحيات يدوية لكل مستخدم.

---

## أولا: ما هو RBAC؟

RBAC = Role-Based Access Control

يعني أن الصلاحيات تأتي من الدور:

```text
User
-> Role
-> Permissions
```

مثال:

```text
Role = Department Director

Permissions:
- View Department Tasks
- Override Stage/Sub-stage Assignment
- View Department Analytics
```

## مميزات RBAC

- بسيط.
- سهل الفهم.
- سهل التنفيذ.
- مناسب للأنظمة الصغيرة أو الأنظمة ذات الهيكل الثابت.

## لماذا لم نستخدم RBAC التقليدي؟

لأن RBAC يفترض أن الأدوار ثابتة ومعروفة مسبقا.

هذا قد يكون مناسبا لو كنا نبني النظام لوزارة واحدة فقط، لكن المنصة تستهدف جهات مختلفة.

مثال:

| نوع الجهة | مسميات القيادة | مسميات الإدارة |
|---|---|---|
| وزارة | Minister, Undersecretary | Director |
| جامعة | President, Dean | Department Head |
| مستشفى | CEO, Medical Director | Department Chair |
| شركة | CEO, VP | General Manager |

لو استخدمنا دورا ثابتا اسمه:

```text
department_director
```

سنضطر لاحقا إلى عمل Mapping غريب:

```text
Dean = department_director
General Manager = department_director
Department Chair = department_director
```

وهذا يجعل المنتج مرتبطا بمسميات حكومية أكثر من اللازم.

المشكلة الأكبر أن بعض الصلاحيات ليست مرتبطة بالمنصب فقط. أحيانا تعتمد على:

- هل المستخدم هو منشئ المهمة؟
- هل هو مالك المرحلة الحالية؟
- هل كان مالكا لمرحلة سابقة؟
- هل المهمة لمست إدارته؟
- هل المهمة سرية؟
- هل لديه نطاق متابعة محدد؟
- هل لديه تفويض مؤقت؟
- هل لديه Audit Grant؟

RBAC التقليدي لا يعبر عن هذه الحالات بشكل نظيف.

---

## ثانيا: ما هو ABAC؟

ABAC = Attribute-Based Access Control

يعني أن قرار الوصول يعتمد على خصائص متعددة، وليس على اسم الدور فقط.

مثال:

```text
User Department = Legal
User Authority Grade = 3
Task Classification = Confidential
Task Current Department = Legal
User Capability = task.view.department_touched
```

ثم يقرر النظام هل يسمح أو يمنع.

## مميزات ABAC

- مرن جدا.
- مناسب للمؤسسات الكبيرة.
- مناسب للأنظمة متعددة الجهات.
- يدعم التصنيفات مثل Public و Internal و Confidential.
- يدعم القواعد التي تعتمد على السياق والعلاقة بالمهمة.

## لماذا لم نستخدم ABAC وحده؟

ABAC وحده يعطيك طريقة لاتخاذ القرار، لكنه لا يكفي لتنظيم المؤسسة نفسها.

هو لا يجيب وحده على أسئلة مثل:

```text
من هو مدير الإدارة؟
من هو Dean؟
من هو CEO؟
من هو المسؤول الأعلى في هذا المسار؟
إلى من يتم التصعيد؟
من يملك صلاحية المتابعة على هذه الإدارات؟
```

لذلك نحتاج بجانبه إلى:

- Positions قابلة للتخصيص.
- Authority Grades.
- Reporting Lines.
- Capabilities قابلة لإعادة الاستخدام.
- Scoped Grants.

---

## ثالثا: ما هو PBAC؟

مصطلح PBAC يستخدم أحيانا بمعنيين مختلفين:

1. Permission-Based Access Control
2. Policy-Based Access Control

لذلك يجب توضيح المقصود.

## Permission-Based Access Control

يعني إعطاء صلاحيات مباشرة لكل مستخدم:

```text
Ahmed:
- view_org_tasks
- create_blueprint
- override_stage_owner
```

هذا مرن، لكنه يصبح صعب الإدارة مع عدد كبير من المستخدمين.

مثال: لو كان لدينا 500 أو 5000 مستخدم، وأصبح لكل مستخدم مجموعة صلاحيات خاصة، ستصبح إدارة الصلاحيات صعبة جدا، ومراجعتها أمنيا أصعب.

لذلك لا نعتمد على الصلاحيات المباشرة لكل مستخدم كالنموذج الأساسي.

نستخدمها فقط كاستثناءات محدودة ومؤرشفة في Audit Trail.

## Policy-Based Access Control

هذا هو الأقرب لما اخترناه.

في هذا الأسلوب لا يكون القرار مجرد:

```text
هل المستخدم لديه Role معين؟
```

بل يكون القرار مبنيا على Policy:

```text
اسمح للمستخدم برؤية المهمة إذا:
- لديه capability مناسبة
- ونطاق الصلاحية يغطي المهمة
- وتصنيف المهمة لا يمنع الوصول
- أو لديه علاقة مباشرة بالمهمة
```

لذلك يمكن وصف نموذجنا بأنه:

```text
Policy-Based ABAC with configurable positions and capabilities
```

---

## النموذج النهائي الذي اخترناه

النظام يعتمد على ست طبقات.

---

## 1. Account Types

Account Type يحدد نوع الحساب من ناحية تقنية، وليس المنصب الإداري.

أمثلة:

```text
internal_user
tenant_admin
external_auditor
platform_admin
```

الفرق مهم:

- `tenant_admin` مسؤول عن إعدادات النظام.
- `external_auditor` حساب خارجي لا يرى شيئا إلا من خلال Audit Grant.
- `internal_user` موظف عادي داخل الجهة.

هذا ليس بديلا عن المناصب. هو فقط تصنيف تقني للحساب.

---

## 2. Positions

Position هو المنصب الحقيقي داخل الهيكل التنظيمي.

وهو قابل للتخصيص بالكامل من قبل كل Tenant.

أمثلة:

```text
Minister
Undersecretary
Director
Dean
Department Head
CEO
VP
General Manager
Employee
```

الميزة هنا أن النظام لا يحتاج أن يعرف معنى "Dean" أو "General Manager" ككود ثابت.

العميل يعرف المناصب، ثم يربط بها الصلاحيات المناسبة.

---

## 3. Authority Grades

Authority Grade يحدد مستوى السلطة أو seniority داخل الجهة.

مثال وزارة:

```text
Grade 1 = Minister
Grade 2 = Undersecretary
Grade 3 = Assistant Undersecretary
Grade 4 = Director
```

مثال جامعة:

```text
Grade 1 = President
Grade 2 = Vice President
Grade 3 = Dean
Grade 4 = Department Head
```

الهدف أن النظام يفهم التسلسل الإداري بدون الاعتماد على اسم المنصب.

يستخدم هذا في:

- التصعيد.
- التفويض.
- تحديد من يعتبر أعلى سلطة.
- بعض قواعد التجاوز مثل Override Stage/Sub-stage Assignment.

لكن Authority Grade وحده لا يكفي. قد يكون شخصان في نفس الدرجة، لكن صلاحياتهما مختلفة.

---

## 4. Capabilities

Capability هي صلاحية قابلة لإعادة الاستخدام.

أمثلة:

```text
task.view.organization
task.view.department_touched
task.view.follow_up_scope
task.override_assignment
task.classify.confidential
task.confidential.view_override
blueprint.create.organization
analytics.view.department
audit.create_grant
```

بدلا من أن نقول:

```text
Department Director can view department tasks
```

نقول:

```text
أي منصب لديه task.view.department_touched
يمكنه رؤية مهام إدارته حسب النطاق والتصنيف
```

وهكذا يمكن للعميل أن يعطي نفس الصلاحية إلى:

- Director
- Dean
- General Manager
- Department Chair

بدون تغيير الكود.

---

## 5. Scoped Grants

ليست كل صلاحية تكون على مستوى المؤسسة بالكامل.

أحيانا الصلاحية تكون ضمن نطاق محدد:

```text
tenant
own_department
specific_department
department_tree
own_tasks
audit_grant
```

مثال:

```text
task.view.follow_up_scope
```

هذه الصلاحية وحدها لا تكفي. يجب أن نعرف نطاق المتابعة:

```text
Department = Legal
Department = Finance
Blueprint Category = Executive Directive
```

وهذا يحل مشكلة Follow-Up Specialist.

المتابعة ليست Role ثابت. هي:

```text
Capability + Monitoring Scope
```

قد يسميها العميل:

- Follow-Up Specialist
- PMO Analyst
- Operations Coordinator
- Dean's Office Coordinator
- Compliance Tracker

والنظام لا يتأثر بالاسم.

---

## 6. Relationship-Based Access

بعض الصلاحيات تأتي من علاقة المستخدم بالمهمة، وليس من منصبه.

أمثلة:

```text
Task Initiator
Current Stage/Sub-stage Assignee
Past Stage/Sub-stage Assignee
Named Confidential Participant
Confidential Governance Participant
External Auditor with Audit Grant
```

مثال:

إذا كان المستخدم هو مالك المرحلة الحالية، يجب أن يرى المهمة ويستطيع تنفيذ المرحلة، حتى لو لم يكن لديه صلاحية واسعة لرؤية مهام الإدارة كلها.

وإذا كان المستخدم هو منشئ المهمة، يحتفظ بحق رؤية المهمة طوال دورة حياتها.

وإذا كان External Auditor، فلا يرى شيئا افتراضيا، بل فقط ما تغطيه Audit Grant محددة.

---

## كيف يتم اتخاذ قرار الوصول؟

النظام لا يسأل سؤالا واحدا فقط مثل:

```text
ما هو Role المستخدم؟
```

بل يسأل مجموعة أسئلة:

```text
ما نوع الحساب؟
ما المنصب الحالي؟
ما درجة السلطة؟
ما الصلاحيات الممنوحة؟
ما نطاق الصلاحية؟
ما إدارة المستخدم؟
هل المهمة لمست هذه الإدارة؟
هل المستخدم منشئ المهمة؟
هل المستخدم مالك مرحلة حالي أو سابق؟
ما تصنيف المهمة؟
هل المهمة Confidential؟
هل يوجد Governance Participant؟
هل يوجد Override مبرر ومؤرشف؟
هل يوجد Audit Grant؟
```

ثم تطبق Policy واضحة لاتخاذ قرار السماح أو المنع.

---

## مثال عملي: رؤية مهام الإدارة

في RBAC التقليدي:

```text
Role = Department Director
-> View Department Tasks
```

في نموذجنا:

```text
Position = Dean
Capability = task.view.department_touched
Scope = own_department
```

أو:

```text
Position = General Manager
Capability = task.view.department_touched
Scope = department_tree
```

النتيجة واحدة، لكن النموذج لا يعتمد على اسم ثابت مثل Department Director.

---

## مثال عملي: المتابعة

في RBAC التقليدي:

```text
Role = Follow-Up Specialist
-> View Follow-Up Board
```

في نموذجنا:

```text
User has capability = task.view.follow_up_scope
Monitoring Scope = Legal + Finance
Task Status = active / overdue / at_risk / suspended
```

هذا يسمح بمتابعة مرنة حسب الإدارة أو الفئة أو النطاق، بدون تثبيت مسمى وظيفي داخل النظام.

---

## مثال عملي: المهام السرية

في نموذج بسيط جدا:

```text
CEO can view everything
```

هذا خطر، لأن بعض المهام السرية قد تكون:

- تحقيق داخلي.
- شكوى Whistleblower.
- موضوع HR حساس.
- رأي قانوني محمي.
- بيانات مريض في مستشفى.
- مراجعة مناقصة أو مشتريات.

لذلك قررنا أن:

```text
Confidential لا تعني أن كل القيادات تراها تلقائيا.
```

لكن في نفس الوقت لا نريد أن يستطيع موظف منخفض السلطة إنشاء مهمة سرية تختفي عن كل جهات الرقابة.

لذلك استخدمنا ثلاث آليات:

1. Named Confidential Participants.
2. Confidential Governance Participants.
3. Justified and audited confidential override.

بهذا نحافظ على السرية، ونحافظ أيضا على الرقابة والحوكمة.

---

## لماذا هذا مهم للقطاع الحكومي؟

في الجهات الحكومية توجد حساسية عالية حول:

- التسلسل الإداري.
- سرية المراسلات.
- المساءلة.
- التدقيق الخارجي.
- المتابعة على مستوى القيادة.
- عدم حذف أو إخفاء السجل.

النموذج المختار يدعم ذلك لأنه:

- يحترم الهيكل التنظيمي.
- يدعم المناصب والدرجات.
- يسمح بمتابعة عابرة للإدارات ضمن نطاق.
- يحفظ Audit Trail.
- يمنع الوصول العشوائي للمهام السرية.
- يسمح برقابة مضبوطة على المهام السرية.

---

## لماذا هذا مهم للقطاع الخاص؟

في الشركات والجامعات والمستشفيات، المسميات تختلف كثيرا.

قد لا يوجد "Director"، لكن يوجد:

- Dean
- General Manager
- VP
- Department Chair
- Compliance Officer
- PMO Lead

النموذج المختار يسمح لكل جهة أن تستخدم مسمياتها الخاصة بدون تعديل النظام.

كما أنه يدعم حالات القطاع الخاص مثل:

- Legal privilege.
- HR investigations.
- Compliance workflows.
- Department-level analytics.
- CEO or board visibility.
- Internal audit.

---

## مقارنة مختصرة

| النموذج | هل يناسب المشروع؟ | السبب |
|---|---|---|
| RBAC التقليدي | لا يكفي | يعتمد على أدوار ثابتة، وهذا يربط المنتج بمسميات محددة. |
| Permission-Based لكل مستخدم | لا كنموذج أساسي | مرن، لكنه صعب الإدارة والمراجعة على عدد كبير من المستخدمين. |
| ABAC وحده | لا يكفي وحده | مرن، لكنه لا ينظم المناصب والهيكل الإداري والتدرج الوظيفي. |
| Policy-Based ABAC + Positions + Capabilities | نعم | يجمع المرونة مع قابلية الإدارة ويدعم أكثر من نوع مؤسسة. |

---

## لماذا لم نختر النموذج الأبسط؟

لأن الأبسط على مستوى الكود قد يصبح أغلى لاحقا على مستوى المنتج.

لو بدأنا بأدوار ثابتة مثل:

```text
org_executive
department_director
follow_up_specialist
stage_owner
```

سنواجه مشاكل عند أول عميل لا يستخدم هذه المسميات.

وسنضطر لاحقا إلى تعديل:

- ERD.
- IAM.
- Visibility Rules.
- Onboarding.
- Analytics.
- Blueprint ownership.
- Audit model.

لذلك الأفضل تصميم الأساس بشكل صحيح قبل ERD.

---

## الجملة المناسبة للعرض

```text
لم نعتمد على RBAC التقليدي لأن المنصة ليست موجهة لهيكل حكومي واحد فقط، بل لجهات متعددة لكل منها مسميات ومناصب وتسلسل إداري مختلف. لذلك اخترنا نموذجا قائما على السياسات يجمع بين المناصب القابلة للتخصيص، ودرجات السلطة، والصلاحيات القابلة لإعادة الاستخدام، وقواعد ABAC التي تراعي علاقة المستخدم بالمهمة وتصنيفها ونطاقها. هذا يسمح لكل جهة بتعريف هيكلها وصلاحياتها دون تعديل الكود، مع الحفاظ على السرية والحوكمة وقابلية التدقيق.
```

---

## القرار النهائي

النموذج المعتمد ليس:

```text
RBAC فقط
```

وليس:

```text
صلاحيات مباشرة لكل مستخدم
```

وليس:

```text
ABAC خام بدون هيكل تنظيمي
```

بل هو:

```text
Policy-Based ABAC
مدعوم بمناصب قابلة للتخصيص
وصلاحيات قابلة لإعادة الاستخدام
ونطاقات صلاحية
وعلاقات مباشرة بين المستخدم والمهمة
```

وهذا هو النموذج الأنسب لمنصة multi-tenant قابلة للبيع لجهات حكومية وخاصة مختلفة.
