// Mock UI data for the Famboook Staff App dashboard shell.
// This is local, presentation-only data — no backend endpoint exists yet.

export type SummaryCard = {
  label: string;
  value: string;
  hint: string;
};

export const summaryCards: SummaryCard[] = [
  { label: "إجمالي الأسر", value: "1,284", hint: "أسرة مسجلة" },
  { label: "إجمالي الأفراد", value: "5,672", hint: "فرد في السجل" },
  { label: "بانتظار المراجعة", value: "34", hint: "سجل يحتاج مراجعة" },
  { label: "طلبات التغيير", value: "12", hint: "طلب قيد المعالجة" },
];

export type RecentFamily = {
  familyCode: string;
  householdHead: string;
  memberCount: number;
  status: "نشطة" | "قيد المراجعة" | "بحاجة لتحديث";
  updatedAt: string;
};

export const recentFamilies: RecentFamily[] = [
  {
    familyCode: "FAM-001284",
    householdHead: "محمد أحمد الشريف",
    memberCount: 6,
    status: "نشطة",
    updatedAt: "قبل ساعتين",
  },
  {
    familyCode: "FAM-001283",
    householdHead: "فاطمة سالم القرني",
    memberCount: 4,
    status: "قيد المراجعة",
    updatedAt: "قبل 5 ساعات",
  },
  {
    familyCode: "FAM-001282",
    householdHead: "خالد يوسف النجار",
    memberCount: 8,
    status: "نشطة",
    updatedAt: "أمس",
  },
  {
    familyCode: "FAM-001281",
    householdHead: "عائشة عبد الله الحربي",
    memberCount: 3,
    status: "بحاجة لتحديث",
    updatedAt: "قبل يومين",
  },
  {
    familyCode: "FAM-001280",
    householdHead: "إبراهيم ناصر العتيبي",
    memberCount: 5,
    status: "نشطة",
    updatedAt: "قبل 3 أيام",
  },
];

export type ReviewTask = {
  title: string;
  context: string;
};

export const reviewTasks: ReviewTask[] = [
  {
    title: "مراجعة طلب تغيير السكن",
    context: "عائلة رقم FAM-001283",
  },
  {
    title: "التحقق من مستند الهوية",
    context: "شخص رقم PER-004512",
  },
  {
    title: "مراجعة تكرار محتمل",
    context: "عائلة رقم FAM-001279",
  },
  {
    title: "الموافقة على تغيير رب الأسرة",
    context: "عائلة رقم FAM-001265",
  },
];
