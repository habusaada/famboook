import { Code } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import type { AnnouncementLevel, AnnouncementStatus, AnnouncementSummary } from "@/lib/api/announcements";
import { announcementStatusLabels, announcementStatusTones, audienceTypeLabels } from "@/lib/utils/announcement-labels";

// Presentation-only announcement pieces (FB-WEB-02B), used by the Staff list
// and, with synthetic data, by the public preview. No hook, no request, no
// navigation: the caller decides what surrounds a row. Every state is text.

const fmt = (n: number) => n.toLocaleString("ar");
const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("ar", { day: "numeric", month: "short", year: "numeric" }) : null);

export function AnnouncementStatusBadge({ status }: { status: AnnouncementStatus }) {
  return (
    <StatusBadge tone={announcementStatusTones[status] ?? "neutral"}>
      <span data-announcement-status={status}>{announcementStatusLabels[status] ?? status}</span>
    </StatusBadge>
  );
}

export function AnnouncementLevelBadge({ level }: { level: AnnouncementLevel }) {
  return level === "IMPORTANT" ? (
    <StatusBadge tone="warning">
      <span data-announcement-level="IMPORTANT">مهم</span>
    </StatusBadge>
  ) : (
    <StatusBadge tone="neutral">
      <span data-announcement-level="NORMAL">عادي</span>
    </StatusBadge>
  );
}

/** The most relevant date of a row: published, else queued, else created. */
function milestone(a: AnnouncementSummary): string | null {
  if (a.published_at) return `نُشر ${day(a.published_at)}`;
  if (a.queued_at) return `طُلب النشر ${day(a.queued_at)}`;
  if (a.created_at) return `أُنشئ ${day(a.created_at)}`;
  return null;
}

/** One announcement's code, badges, title and context. */
export function AnnouncementRowContent({ announcement }: { announcement: AnnouncementSummary }) {
  const when = milestone(announcement);
  return (
    <span className="flex min-w-0 flex-1 flex-col gap-1.5">
      <span className="flex flex-wrap items-center gap-2">
        <Code className="text-xs text-brand-800">{announcement.notice_code}</Code>
        <AnnouncementStatusBadge status={announcement.status} />
        <AnnouncementLevelBadge level={announcement.level} />
      </span>
      <span className="truncate text-sm font-semibold text-foreground">{announcement.title}</span>
      <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
        {announcement.audience_type && <span>{audienceTypeLabels[announcement.audience_type] ?? announcement.audience_type}</span>}
        {when && (
          <>
            <span aria-hidden>·</span>
            <span>{when}</span>
          </>
        )}
        {announcement.recipient_count_at_publication !== null && (
          <>
            <span aria-hidden>·</span>
            <span data-announcement-recipients>
              المستلمون عند النشر: <bdi className="tabular-nums">{fmt(announcement.recipient_count_at_publication)}</bdi>
            </span>
          </>
        )}
      </span>
    </span>
  );
}
