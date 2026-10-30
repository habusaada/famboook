import type { StatusTone } from "@/components/shared/status-badge";
import type { AnnouncementAudienceType, AnnouncementStatus } from "@/lib/api/announcements";

// The display wording of announcement states (FB-NOTIFY-01C.3c), apart from
// lib/utils/announcement.ts so a presentation-only screen can use it without
// any runtime dependency on the API client (FB-WEB-02B). Type imports only.

export const announcementStatusLabels: Record<AnnouncementStatus, string> = {
  DRAFT: "مسودة",
  QUEUED: "في انتظار النشر",
  PUBLISHING: "قيد النشر",
  PUBLISHED: "منشور",
  PUBLISH_FAILED: "تعذّر النشر",
  ARCHIVED: "مؤرشف",
  CANCELLED: "ملغى",
};

export const announcementStatusTones: Record<AnnouncementStatus, StatusTone> = {
  DRAFT: "neutral",
  QUEUED: "info",
  PUBLISHING: "info",
  PUBLISHED: "success",
  PUBLISH_FAILED: "danger",
  ARCHIVED: "neutral",
  CANCELLED: "neutral",
};

export const audienceTypeLabels: Record<AnnouncementAudienceType, string> = {
  STAFF_FAMILIES: "أسر محددة",
  STAFF_ALL_ACTIVE: "جميع الأسر النشطة",
  COORDINATOR_SCOPE: "كامل نطاق التنسيق",
  COORDINATOR_SELECTION: "أسر محددة من النطاق",
};
