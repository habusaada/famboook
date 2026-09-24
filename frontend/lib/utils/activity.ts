import {
  FilePlus2,
  FilePen,
  HeartPulse,
  HeartOff,
  MapPin,
  Tent,
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
};

export function formatActivityTime(iso: string): string {
  return new Date(iso).toLocaleString("ar", {
    dateStyle: "long",
    timeStyle: "short",
  });
}
