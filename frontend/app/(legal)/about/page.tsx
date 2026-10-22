import type { Metadata } from "next";
import { InfoSection as Section, infoCard } from "@/components/legal/info-section";
import { OPERATOR, OPERATOR_DISPLAY_NAME } from "@/lib/legal/operator";
import { legalMetadata, legalPage } from "@/lib/legal/pages";
import { RELEASE, RELEASE_DESIGNATION } from "@/lib/release";
import { formatDateLong } from "@/lib/utils/date";

export const metadata: Metadata = legalMetadata("about");

const card = infoCard;

/** The approved onboarding sequence (FB-LEGAL-01 D10; docs/governance/FB-GOV-01 §2), in plain Arabic. */
const ONBOARDING_STEPS = [
  "تتقدّم العائلة أو العشيرة بطلب رسمي لاستخدام Famboook.",
  "يرشّح مجلس العائلة أو العشيرة المتفق عليه منسقًا ويكلّفه.",
  "تقدّم العائلة أو العشيرة كشفًا أوليًا يتضمّن أرقام الهوية على الأقل.",
  "يستكمل فريق Famboook بيانات أرباب الأسر بالتعاون مع العشيرة والمؤسسات الشريكة ذات الصلة.",
  "تُعَدّ البيانات في نموذج الإدخال المعتمد في Famboook، وفق أعمدته وقواعد التحقق منه.",
  "يخضع الملف لمراجعة جودة البيانات وسلامتها.",
  "يعتمد منسق العشيرة المكلَّف رسميًا وإدارة Famboook الملف النهائي معًا.",
  "يُدخَل الملف المعتمد من الطرفين وحده عبر إجراءات الإدخال القائمة.",
  "يفعّل أرباب الأسر حساباتهم في بوابة الأسرة ويقدّمون طلبات تحديث بياناتهم.",
] as const;

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm font-medium text-foreground sm:text-end">{children}</dd>
    </div>
  );
}

/**
 * «عن فامبوك» (FB-LEGAL-01B, content FB-LEGAL-01C.3): what the platform does
 * (verified against the application), the approved clan onboarding procedure
 * (FB-LEGAL-01 D10, docs/governance/FB-GOV-01) and the approved operator
 * identity (D1). No legal claim, contact detail, ownership claim or guarantee:
 * contact details belong to /contact, which awaits publication approval.
 */
export default function AboutPage() {
  const page = legalPage("about");

  return (
    <article className="flex flex-col gap-4" aria-labelledby="legal-title" data-legal-page="about" data-legal-status="PUBLISHED">
      <h1 id="legal-title" className="text-2xl leading-snug font-bold text-foreground">
        {page.title}
      </h1>

      <Section id="product" title="المنصة">
        <p>
          <bdi>{RELEASE.product}</bdi> نظام لسجل العائلات وإدارة الحالات. يجمع في مكان واحد بيانات الأسر وأفرادها، ويدعم فريق العمل في متابعة أوضاع الأسر واحتياجاتها، ويتيح لأرباب الأسر الاطلاع على بيانات أسرهم.
        </p>
      </Section>

      <Section id="registry" title="سجل العائلات">
        <p>
          يحفظ السجل بيانات كل أسرة وأفرادها وصلاتهم برب الأسرة، وبيانات السكن الحالي والأصلي وحالة النزوح. لكل أسرة ولكل شخص رمز تعريف دائم، ويُطابَق الأشخاص برقم الهوية دون دمج تلقائي.
        </p>
      </Section>

      <Section id="family-portal" title="بوابة الأسرة">
        <p>
          يفعّل رب الأسرة المسجّل حسابه برقم هويته وبرمز تحقق يصل إلى رقم الجوال المسجّل، ثم يطّلع على بيانات أسرته ويقدّم طلبات تحديثها. يراجع فريق العمل كل طلب قبل تطبيقه. تُتاح خدمات البوابة على مراحل.
        </p>
      </Section>

      <Section id="coordinators" title="المنسقون">
        <p>
          يكلّف مجلس العائلة أو العشيرة منسقًا يمثّلها. يمنحه فريق {RELEASE.product} الدخول إلى مساحة التنسيق ضمن نطاق محدَّد، فيتابع الأسر الواقعة في نطاقه ويقدّم مقترحات تسجيل أسر أو إضافة أفراد، ولا تُطبَّق مقترحاته إلا بعد مراجعة فريق العمل.
        </p>
      </Section>

      <Section id="staff" title="فريق العمل">
        <p>
          يدير الموظفون المخوّلون السجل، ويراجعون طلبات التحديث، ويُعدّون التقييمات، ويتابعون الاحتياجات والمساعدات، كلٌّ بحسب الصلاحيات المحدَّدة لدوره.
        </p>
      </Section>

      <Section id="onboarding" title="انضمام العائلات والعشائر">
        <ol className="list-decimal space-y-1.5 ps-5" data-onboarding-steps>
          {ONBOARDING_STEPS.map((step) => (
            <li key={step}>{step}</li>
          ))}
        </ol>
        <p className="mt-2 text-muted-foreground">لا يُعدّ الكشف الأولي الذي تقدّمه العشيرة ملفَّ الإدخال النهائي؛ يُدخَل فقط الملف الذي اجتاز المراجعة واعتمده الطرفان.</p>
      </Section>

      <section className={card} aria-labelledby="about-release">
        <h2 id="about-release" className="text-base font-semibold text-foreground">
          الإصدار
        </h2>
        <dl className="mt-1 divide-y divide-border/70">
          <Row label="الإصدار" field="release">
            <bdi dir="ltr" data-release-designation>
              {RELEASE_DESIGNATION}
            </bdi>
          </Row>
          <Row label="وصف الإصدار" field="release-label">
            <bdi dir="ltr">{RELEASE.label}</bdi>
          </Row>
          <Row label="بدء التشغيل الفعلي" field="launch">
            <time dateTime={RELEASE.launchDate}>{formatDateLong(RELEASE.launchDate)}</time>
          </Row>
        </dl>
      </section>

      <section className={card} aria-labelledby="about-operator">
        <h2 id="about-operator" className="text-base font-semibold text-foreground">
          الجهة المشغّلة
        </h2>
        <p className="mt-2 text-sm leading-relaxed text-foreground" data-operator>
          {OPERATOR.roleAr}: {OPERATOR_DISPLAY_NAME}
        </p>
      </section>
    </article>
  );
}
