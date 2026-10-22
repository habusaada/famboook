import type { Metadata } from "next";
import { InfoSection } from "@/components/legal/info-section";
import { OPERATOR, OPERATOR_DISPLAY_NAME } from "@/lib/legal/operator";
import { legalMetadata, legalPage } from "@/lib/legal/pages";

export const metadata: Metadata = legalMetadata("contact");

/**
 * «التواصل» (FB-LEGAL-01D): the ONE verified active channel — the operator's
 * institutional email — for inquiries and support. No form, ticket, telephone
 * or postal address; no response-time promise. The planned Famboook support
 * address is added only on launch day, after the mailbox is verified
 * (docs/deployment/FB-LEGAL-01D-LAUNCH-DAY-CONTACT-CHECKLIST.md).
 */
export default function ContactPage() {
  const page = legalPage("contact");

  return (
    <article className="flex flex-col gap-4" aria-labelledby="legal-title" data-legal-page="contact" data-legal-status="PUBLISHED">
      <h1 id="legal-title" className="text-2xl leading-snug font-bold text-foreground">
        {page.title}
      </h1>

      <InfoSection id="email" title="البريد الإلكتروني">
        <p>للاستفسارات وطلبات الدعم المتعلقة بمنصة Famboook، راسل {OPERATOR_DISPLAY_NAME}، الجهة المشغّلة للمنصة، على العنوان:</p>
        <p className="mt-3">
          <a
            href={`mailto:${OPERATOR.contactEmail}`}
            dir="ltr"
            className="inline-flex min-h-11 items-center rounded-md text-base font-semibold text-brand-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
            data-contact-email
          >
            {OPERATOR.contactEmail}
          </a>
        </p>
      </InfoSection>

      <InfoSection id="topics" title="ما يمكنك مراسلتنا بشأنه">
        <ul className="list-disc space-y-1.5 ps-5">
          <li>حساب بوابة الأسرة وتفعيله وتسجيل الدخول إليه.</li>
          <li>الاستفسار عن بيانات أسرتك في السجل أو طلب تصحيحها.</li>
          <li>الأسئلة المتعلقة بخصوصية بياناتك في المنصة.</li>
          <li>المراسلات العامة مع الجهة المشغّلة.</li>
        </ul>
      </InfoSection>

      <InfoSection id="before-writing" title="قبل أن تراسلنا">
        <ul className="list-disc space-y-1.5 ps-5">
          <li>لا ترسل كلمة المرور أو رمز التحقق في أي رسالة؛ فهما لاستخدامك الشخصي فقط ولا يحتاج إليهما فريق العمل.</li>
          <li>اذكر موضوع رسالتك بوضوح، ورمز أسرتك إن كانت رسالتك تخصّ بياناتها.</li>
          <li>إذا كنت رب أسرة ولديك حساب في بوابة الأسرة، فيمكنك تقديم طلبات تحديث البيانات من داخل البوابة عند إتاحة الخدمة.</li>
        </ul>
      </InfoSection>
    </article>
  );
}
