import type { CivilLookupRelation, CivilLookupSectionKey } from "@/lib/types/api/civil-lookup";

// FB-CIVIL-02A — Arabic labels of the Civil Registry Lookup. Values are shown
// exactly as the source gives them: a field whose codes are not documented by
// the source owner keeps its source key in the label, and no code is ever
// translated or interpreted (ADR §L-4).

export const MISSING = "غير متوفر";

export const SECTION_LABELS: Record<CivilLookupSectionKey, string> = {
  personal: "البيانات الشخصية",
  family: "البيانات الأسرية",
  geographic: "البيانات الجغرافية",
  social: "البيانات الاجتماعية والمهنية",
  reference: "البيانات المرجعية الإضافية",
};

export const FIELD_LABELS: Record<string, string> = {
  mainid: "رقم الهوية",
  fullname: "الاسم الكامل",
  name1: "الاسم الأول",
  father: "اسم الأب",
  grandfath: "اسم الجد",
  family: "اسم العائلة",
  gender: "الجنس (رمز المصدر)",
  datebirth: "تاريخ الميلاد",
  age: "العمر",
  birthplace: "مكان الميلاد (رمز المصدر)",
  maritalst: "الحالة الاجتماعية (رمز المصدر)",
  deathdate: "تاريخ الوفاة",
  presonstate: "حالة الشخص (رمز المصدر)",
  fatherid: "رقم هوية الأب",
  motherid: "رقم هوية الأم",
  partnerid: "رقم هوية الزوج أو الزوجة",
  householdid: "رقم الأسرة (رمز المصدر)",
  householdrel: "الصلة برب الأسرة (رمز المصدر)",
  prevfamily: "العائلة السابقة",
  govern: "المحافظة (رمز المصدر)",
  city: "المدينة (رمز المصدر)",
  street: "الشارع",
  worker: "حقل المصدر worker",
  worktype: "نوع العمل",
  job: "المهنة",
  civilg: "حقل المصدر civilg",
  cwloc: "حقل المصدر cwloc",
  mgrade: "حقل المصدر mgrade",
  mstartw: "حقل المصدر mstartw",
  msalary: "حقل المصدر msalary",
  sert1: "حقل المصدر sert1",
  phonenum: "رقم الهاتف",
  att: "حقل المصدر att",
  source: "حقل المصدر source",
  select: "حقل المصدر select",
};

export const RELATION_LABELS: Record<CivilLookupRelation, string> = {
  FATHER: "الأب",
  MOTHER: "الأم",
  SPOUSE: "الزوج أو الزوجة",
  HOUSEHOLD: "أفراد الأسرة (حسب رقم الأسرة)",
  CHILDREN: "الأبناء (حسب رقم هوية الأب أو الأم)",
};

export const LINK_STATUS_LABELS = {
  NOT_IN_RELEASE: "غير موجود في الإصدار المنشور",
  EMPTY: MISSING,
  DISABLED: "غير مفعّل حاليًا",
} as const;

/** Arabic role names (the Staff names follow App\Support\StaffRoles::LABELS). */
export const ROLE_LABELS: Record<string, string> = {
  SUPER_ADMIN: "مدير النظام",
  ADMINISTRATOR: "مسؤول إداري",
  DATA_ENTRY: "مدخل بيانات",
  REVIEWER: "مراجع",
  SOCIAL_WORKER: "أخصائي اجتماعي",
  REPORTS_VIEWER: "مطلع على التقارير",
  COORDINATOR: "منسق",
  FAMILY_USER: "مستخدم بوابة الأسرة",
};

export const roleLabel = (role: string) => ROLE_LABELS[role] ?? role;

/** A date-time in Gaza local time (the quota resets at Gaza midnight). */
export function gazaTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("ar", {
    timeZone: "Asia/Gaza",
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}
