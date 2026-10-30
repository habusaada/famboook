import type { AnnouncementSummary } from "@/lib/api/announcements";
import type { FamilyUser } from "@/lib/api/family-auth";
import type { FamilyHousehold, FamilyMember } from "@/lib/api/family-household";
import type { FamilyNotificationSummary } from "@/lib/api/family-notifications";
import type { FamilyMemberDetail } from "@/lib/types/api/family";

// FB-WEB-02B — the ONLY data of the public preview on the homepage: invented and
// fixed. No real person, family, National ID or mobile number; every identifier
// field a real type allows is null, and codes carry DEMO. Figures are
// illustrative, never operational statistics. Type imports only: nothing here
// reaches the API client at run time.

export const DEMO_NOTICE = "بيانات توضيحية اصطناعية لا تمثل أشخاصًا أو أسرًا حقيقية، والأرقام المعروضة أمثلة وليست إحصاءات تشغيلية.";

export const demoDashboard = {
  kpis: { families: 124, members: 542, requests: 7, announcements: 2 },
  requests: [
    { label: "جديدة", value: 2, hint: "بانتظار الفرز" },
    { label: "قيد المراجعة", value: 3, hint: "لدى فريق العمل" },
    { label: "بانتظار توضيح", value: 1, hint: "من مقدّم الطلب" },
    { label: "مطبّقة", value: 1, hint: "بعد الاعتماد" },
  ],
  branches: [
    { key: "a", label: "فرع تجريبي (أ)", value: 48, tone: "brand" },
    { key: "b", label: "فرع تجريبي (ب)", value: 42, tone: "info" },
    { key: "c", label: "فرع تجريبي (ج)", value: 34, tone: "success" },
  ],
  pending: [
    { id: "DEMO-1084", title: "تحديث عنوان سكن أسرة", entity: "الأسرة DEMO-001", meta: "بانتظار مراجعة فريق العمل" },
    { id: "DEMO-1085", title: "إضافة فرد إلى بيانات الأسرة", entity: "الأسرة DEMO-002", meta: "مرفق البيانات الأساسية" },
  ],
} as const;

export const demoRegistryFamily = { code: "DEMO-001", branch: "فرع تجريبي (أ)", status: "ACTIVE" } as const;

type DemoRegistryMember = Pick<FamilyMemberDetail, "person_code" | "full_name" | "gender" | "is_household_head" | "is_active"> & {
  relationship: { code: string; name: string };
  access: string;
};

export const demoRegistryMembers: DemoRegistryMember[] = [
  { person_code: "DEMO-P01", full_name: "طارق ع.", gender: "MALE", is_household_head: true, is_active: true, relationship: { code: "HEAD", name: "رب الأسرة" }, access: "إدارة بيانات الأسرة" },
  { person_code: "DEMO-P02", full_name: "مريم س.", gender: "FEMALE", is_household_head: false, is_active: true, relationship: { code: "SPOUSE", name: "زوج/زوجة" }, access: "—" },
  { person_code: "DEMO-P03", full_name: "ياسين ط.", gender: "MALE", is_household_head: false, is_active: true, relationship: { code: "SON", name: "ابن" }, access: "—" },
  { person_code: "DEMO-P04", full_name: "هدى ط.", gender: "FEMALE", is_household_head: false, is_active: true, relationship: { code: "DAUGHTER", name: "ابنة" }, access: "—" },
];

export const demoFamilyUser: FamilyUser = {
  display_name: "مستخدم افتراضي",
  roles: ["FAMILY_USER"],
  coordinator: false,
  coordinator_space: false,
  notifications: true,
  previous_sign_in_at: null,
  first_sign_in: false,
  context: { available: true },
} as FamilyUser;

export const demoHousehold: FamilyHousehold = {
  family_code: "DEMO-001",
  clan_name: "عائلة افتراضية",
  branch_name: "فرع تجريبي (أ)",
  head: { full_name: "مستخدم افتراضي" },
  declared_household_size: 4,
  declared_at: null,
  registered_member_count: 4,
};

const member = (over: Pick<FamilyMember, "member_ref" | "full_name" | "relationship" | "is_household_head" | "gender">): FamilyMember => ({
  available: true,
  birth_date: null,
  marital_status: null,
  life_status: "ALIVE",
  death_date: null,
  national_id_masked: null,
  mobile_masked: null,
  alternate_mobile_masked: null,
  alternate_mobile_owner_relation: null,
  membership_started_at: null,
  ...over,
});

export const demoMembers: FamilyMember[] = [
  member({ member_ref: "demo-1", full_name: "مستخدم افتراضي", relationship: { code: "HEAD", name: "رب الأسرة" }, is_household_head: true, gender: "MALE" }),
  member({ member_ref: "demo-2", full_name: "مريم س.", relationship: { code: "SPOUSE", name: "زوج/زوجة" }, is_household_head: false, gender: "FEMALE" }),
];

const notification = (over: Pick<FamilyNotificationSummary, "id" | "title" | "kind" | "level" | "viewed_at" | "acknowledged_at">): FamilyNotificationSummary => ({
  notice_code: `DEMO-${over.id}`,
  source: "إدارة Famboook",
  action_path: null,
  published_at: "2026-10-01T09:00:00Z",
  expires_at: null,
  expired: false,
  requires_acknowledgement: over.level === "IMPORTANT",
  acknowledged_late: false,
  available_actions: [],
  ...over,
});

export const demoNotifications: FamilyNotificationSummary[] = [
  notification({ id: "n1", title: "تحديث بيانات الطلبة الجامعيين للفصل الحالي", kind: "ANNOUNCEMENT", level: "IMPORTANT", viewed_at: "2026-10-02T09:00:00Z", acknowledged_at: "2026-10-02T09:05:00Z" }),
  notification({ id: "n2", title: "موعد اللقاء السنوي للأسرة", kind: "ANNOUNCEMENT", level: "NORMAL", viewed_at: null, acknowledged_at: null }),
];

const announcement = (over: Pick<AnnouncementSummary, "id" | "notice_code" | "title" | "level" | "audience_type">): AnnouncementSummary => ({
  status: "PUBLISHED",
  expires_at: null,
  created_at: "2026-09-30T09:00:00Z",
  queued_at: "2026-10-01T08:59:00Z",
  published_at: "2026-10-01T09:00:00Z",
  recipient_count_at_publication: null,
  publish_attempts: 1,
  publish_failure_code: null,
  publish_failed_at: null,
  archived_at: null,
  cancelled_at: null,
  cancellation_reason_code: null,
  ...over,
});

export const demoAnnouncements: AnnouncementSummary[] = [
  announcement({ id: "a1", notice_code: "DEMO-A01", title: "موعد اللقاء السنوي للأسرة", level: "NORMAL", audience_type: "STAFF_ALL_ACTIVE" }),
  announcement({ id: "a2", notice_code: "DEMO-A02", title: "تحديث بيانات الطلبة الجامعيين", level: "IMPORTANT", audience_type: "STAFF_FAMILIES" }),
];
