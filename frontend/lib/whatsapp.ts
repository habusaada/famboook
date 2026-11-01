// FB-WEB-05: the public WhatsApp contact channel: one number, three prefilled messages.
// Links only: nothing is sent, stored or tracked, and nothing here can tell
// whether a message was sent.

const DEFAULT_WHATSAPP_NUMBER = "970595990929";

export type WhatsAppIntent = "join" | "support" | "general";

export const WHATSAPP_MESSAGES: Record<WhatsAppIntent, string> = {
  join: `مرحبًا، إدارة منصة فامبوك.

نرغب في تقديم طلب انضمام عائلتنا/عشيرتنا إلى منصة فامبوك.

اسم العائلة/العشيرة:
المحافظة:
اسم ممثل العائلة:
صفته:

نرجو تزويدنا بمتطلبات الانضمام والإجراءات اللازمة.

شكرًا لكم.`,
  support: `مرحبًا، فريق دعم فامبوك.

أحتاج إلى مساعدة فنية في استخدام المنصة.

وصف المشكلة:

شكرًا لكم.`,
  general: `مرحبًا، إدارة منصة فامبوك.

لدي استفسار بخصوص المنصة:`,
};

export const WHATSAPP_PRIVACY_NOTICE =
  "عند اختيار أحد الخيارات ستنتقل إلى واتساب، وتسري هناك سياسة الخصوصية وشروط الخدمة الخاصة به. يُرجى عدم إرسال أرقام الهوية أو البيانات العائلية الحساسة.";

/**
 * The number as wa.me needs it: international digits only (E.164, 8–15 digits).
 * Accepts `+`, a leading `00`, spaces, dashes and parentheses. Null when invalid.
 */
export function normalizeWhatsAppNumber(value: string): string | null {
  const digits = value.trim().replace(/[\s().-]/g, "").replace(/^(\+|00)/, "");
  return /^[1-9]\d{7,14}$/.test(digits) ? digits : null;
}

/**
 * The configured number. Unset or blank is the approved default; an invalid value
 * is null rather than a guess (next.config.ts refuses to build with one).
 */
export function whatsAppNumber(configured: string | undefined = process.env.NEXT_PUBLIC_WHATSAPP_NUMBER): string | null {
  return normalizeWhatsAppNumber(configured?.trim() ? configured : DEFAULT_WHATSAPP_NUMBER);
}

export function whatsAppLink(number: string, intent: WhatsAppIntent): string {
  return `https://wa.me/${number}?text=${encodeURIComponent(WHATSAPP_MESSAGES[intent])}`;
}
