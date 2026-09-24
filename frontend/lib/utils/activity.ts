import {
  ClipboardCheck,
  ClipboardList,
  ClipboardPen,
  BadgeCheck,
  CircleCheckBig,
  CircleX,
  FilePlus2,
  FileSpreadsheet,
  HandHeart,
  HeartHandshake,
  FilePen,
  HeartPulse,
  HeartOff,
  MapPin,
  PackageCheck,
  PackageX,
  Tent,
  Undo2,
  UserCheck,
  UserMinus,
  UserPen,
  UserPlus,
  type LucideIcon,
} from "lucide-react";
import type { FamilyActivityType } from "@/lib/types/api/activity";

// Presentation of the canonical event codes. The backend stores codes only.
export const familyActivityPresentation: Record<
  FamilyActivityType,
  { label: string; icon: LucideIcon }
> = {
  FAMILY_CREATED: { label: "تم إنشاء سجل الأسرة", icon: FilePlus2 },
  FAMILY_UPDATED: { label: "تم تعديل بيانات الأسرة", icon: FilePen },
  FAMILY_MEMBER_ADDED: { label: "تمت إضافة فرد إلى الأسرة", icon: UserPlus },
  PERSON_UPDATED: { label: "تم تعديل بيانات أحد أفراد الأسرة", icon: UserPen },
  RESIDENCE_UPDATED: { label: "تم تعديل بيانات السكن", icon: MapPin },
  DISPLACEMENT_UPDATED: { label: "تم تعديل بيانات النزوح", icon: Tent },
  HEALTH_RECORD_CREATED: { label: "تمت إضافة حالة صحية", icon: HeartPulse },
  HEALTH_RECORD_UPDATED: { label: "تم تعديل حالة صحية", icon: HeartPulse },
  HEALTH_RECORD_CLOSED: { label: "تم إغلاق حالة صحية", icon: HeartOff },
  ASSESSMENT_CREATED: { label: "تم إنشاء تقييم للأسرة", icon: ClipboardList },
  ASSESSMENT_UPDATED: { label: "تم تعديل مسودة تقييم", icon: ClipboardPen },
  ASSESSMENT_COMPLETED: { label: "تم إكمال تقييم للأسرة", icon: ClipboardCheck },
  NEED_CREATED: { label: "تمت إضافة احتياج", icon: HeartHandshake },
  NEED_UPDATED: { label: "تم تعديل احتياج", icon: HandHeart },
  NEED_FULFILLED: { label: "تمت تلبية احتياج", icon: CircleCheckBig },
  NEED_CLOSED: { label: "تم إغلاق احتياج", icon: CircleX },
  ASSISTANCE_NOMINEE_ADDED: { label: "تم الترشيح لمساعدة", icon: UserCheck },
  ASSISTANCE_NOMINEE_REMOVED: { label: "تمت إزالة الترشيح من مساعدة", icon: UserMinus },
  ASSISTANCE_BENEFICIARY_APPROVED: { label: "تم اعتماد مستفيد لمساعدة", icon: BadgeCheck },
  ASSISTANCE_BENEFICIARY_REJECTED: { label: "تم رفض ترشيح لمساعدة", icon: CircleX },
  ASSISTANCE_DELIVERED: { label: "تم تسليم مساعدة", icon: PackageCheck },
  ASSISTANCE_NOT_DELIVERED: { label: "تم تسجيل عدم تسليم مساعدة", icon: PackageX },
  ASSISTANCE_DELIVERY_REVERSED: { label: "تم عكس تسليم مساعدة", icon: Undo2 },
  ASSISTANCE_BENEFICIARY_LISTED: { label: "تم إصدار المستفيد في كشف لجهة خارجية", icon: FileSpreadsheet },
};

export function formatActivityTime(iso: string): string {
  return new Date(iso).toLocaleString("ar", {
    dateStyle: "long",
    timeStyle: "short",
  });
}
