"use client";

import { AlertCircle, Info, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  type FamilyHealthRecord,
  type HealthRecordType,
  healthRecordsOf,
  isAccessFailure,
  useFamilyHouseholdHealthQuery,
} from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import {
  HEALTH_NOTE,
  NOT_RECORDED,
  NO_HEALTH_RECORDS,
  healthStatusLabel,
  healthTypeLabels,
} from "@/lib/utils/family-portal-labels";

// The sub-groups, in the server's type order; a group without records is not rendered.
const GROUPS: { id: string; title: string; types: HealthRecordType[] }[] = [
  { id: "disability", title: "الإعاقة", types: ["DISABILITY"] },
  { id: "chronic", title: "الأمراض المزمنة", types: ["CHRONIC_DISEASE"] },
  { id: "maternal", title: "الحمل والرضاعة", types: ["PREGNANCY", "BREASTFEEDING"] },
];

/** What the record is: «إعاقة حركية», the registered disease name, «حمل», «رضاعة». */
function recordTitle(record: FamilyHealthRecord): string {
  if (record.type === "DISABILITY") {
    return record.disability_type ? `${healthTypeLabels.DISABILITY} ${record.disability_type.name}` : healthTypeLabels.DISABILITY;
  }
  if (record.type === "CHRONIC_DISEASE") return record.condition_name ?? healthTypeLabels.CHRONIC_DISEASE;

  return healthTypeLabels[record.type];
}

function HealthDate({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex items-center justify-between gap-4 text-[13px]">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium text-foreground">
        {value ? <span className="tabular-nums">{formatDateLong(value)}</span> : <span className="font-normal text-subtle-foreground">{NOT_RECORDED}</span>}
      </dd>
    </div>
  );
}

function HealthRecordItem({ record }: { record: FamilyHealthRecord }) {
  return (
    <li className="flex flex-col gap-1.5 rounded-xl border border-border px-3 py-2.5" data-health-record={record.type}>
      <div className="flex items-start justify-between gap-3">
        <p className="min-w-0 text-sm font-semibold break-words text-foreground">
          <bdi>{recordTitle(record)}</bdi>
        </p>
        <span
          className={`shrink-0 rounded-md px-2 py-0.5 text-xs font-medium ${
            record.is_active ? "bg-brand-50 text-brand-800" : "border border-border bg-muted text-muted-foreground"
          }`}
          data-health-status={record.is_active ? "active" : "ended"}
        >
          {healthStatusLabel(record.is_active)}
        </span>
      </div>
      <dl className="flex flex-col gap-1">
        <HealthDate label="تاريخ البداية" value={record.started_at} />
        {/* Not applicable to a current record: omitted. */}
        {!record.is_active && <HealthDate label="تاريخ الانتهاء" value={record.ended_at} />}
      </dl>
    </li>
  );
}

function HealthRecords({ records }: { records: FamilyHealthRecord[] }) {
  if (records.length === 0) {
    // Nothing registered — never a statement that the person is healthy.
    return (
      <p className="mt-2 text-sm text-muted-foreground" data-health-empty>
        {NO_HEALTH_RECORDS}
      </p>
    );
  }

  return (
    <div className="mt-2 flex flex-col gap-4">
      {GROUPS.map((group) => {
        const items = records.filter((record) => group.types.includes(record.type));
        if (items.length === 0) return null;

        return (
          <section key={group.id} aria-labelledby={`member-health-${group.id}-title`} data-health-group={group.id}>
            <h4 id={`member-health-${group.id}-title`} className="mb-1.5 text-[13px] font-semibold text-muted-foreground">
              {group.title}
            </h4>
            <ul className="flex flex-col gap-2">
              {items.map((record, index) => (
                // Records carry no identifier; the server order is stable.
                <HealthRecordItem key={index} record={record} />
              ))}
            </ul>
          </section>
        );
      })}
      <p className="flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground" data-health-note>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        {HEALTH_NOTE}
      </p>
    </div>
  );
}

/**
 * «الحالة الصحية» of ONE member (PWA-3B.6, docs/11 §23a): the registered
 * health facts from the household health query, read strictly by this
 * member's member_ref — another member's records can never render here.
 * Read-only: no edit, add, close or request control. It loads and fails on
 * its own, so the member's other registry data always stays visible.
 */
export function MemberHealth({ memberRef }: { memberRef: string }) {
  const query = useFamilyHouseholdHealthQuery();

  return (
    <section aria-labelledby="member-health-title" aria-busy={query.isPending} data-member-section="health">
      <h3 id="member-health-title" className="text-sm font-semibold text-foreground">
        الحالة الصحية
      </h3>
      {query.data ? (
        <HealthRecords records={healthRecordsOf(query.data, memberRef)} />
      ) : query.isError ? (
        !isAccessFailure(query.error) && (
          <div className="mt-2 flex flex-col items-start gap-2" data-health-error>
            <p className="flex items-center gap-2 text-sm font-medium text-foreground" role="alert">
              <AlertCircle className="size-4 text-danger" aria-hidden />
              تعذّر تحميل البيانات الصحية
            </p>
            <Button variant="outline" className="h-10 gap-2 px-4" onClick={() => query.refetch()} disabled={query.isFetching}>
              <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
              إعادة المحاولة
            </Button>
          </div>
        )
      ) : (
        <div className="mt-2 flex flex-col gap-2" data-health-loading>
          <p role="status" className="sr-only">
            جارٍ تحميل البيانات الصحية
          </p>
          <Skeleton className="h-16 w-full rounded-xl" />
          <Skeleton className="h-16 w-full rounded-xl" />
        </div>
      )}
    </section>
  );
}
