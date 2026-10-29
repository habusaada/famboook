"use client";

import { IdCard, Link2, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import type {
  CivilLookupLink,
  CivilLookupRecord,
  CivilLookupRelation,
  CivilLookupReverseRelation,
  CivilLookupSpace,
} from "@/lib/types/api/civil-lookup";
import { formatCivilDate, recordAge } from "@/lib/utils/civil-date";
import { CivilLookupId } from "./revealable-id";
import { FIELD_LABELS, LINK_STATUS_LABELS, MISSING, RELATION_LABELS, SECTION_LABELS } from "./labels";

const IDENTIFIER_FIELDS = new Set(["mainid", "fatherid", "motherid", "partnerid", "householdid", "phonenum"]);

/** Shown in the record header, not repeated in the sections. */
const HEADER_FIELDS = new Set(["mainid", "fullname"]);

const DATE_FIELDS = new Set(["datebirth", "deathdate"]);

/**
 * One citizen of the active published release (FB-CIVIL-02A, ADR §L-4, §L-8):
 * the sections and fields the API returned for this user's level, raw values
 * ("غير متوفر" when empty), and the linked citizens. Opening a linked citizen
 * or a household / children list is a new, counted lookup.
 *
 * FB-CIVIL-02E: mobile layout (header, stacked fields), dates without a time,
 * and the age computed from the dates — as of today in Gaza, or at death when
 * the record carries a death date (recordAge); the stored `age` is never
 * shown. The National ID is shown exactly as the API
 * returns it for this level (unmasked only at FULL).
 */
export function CitizenDetail({
  record,
  busy,
  space = "staff",
  canReveal = false,
  openingLink,
  onOpenLink,
  onOpenLinks,
}: {
  record: CivilLookupRecord;
  busy: boolean;
  /** FB-CIVIL-02G: the mount the reveal goes through, and whether the eye-icon reveal is offered. */
  space?: CivilLookupSpace;
  canReveal?: boolean;
  openingLink?: CivilLookupRelation | null;
  onOpenLink: (link: CivilLookupLink) => void;
  onOpenLinks: (link: CivilLookupLink, relation: CivilLookupReverseRelation) => void;
}) {
  const fields = record.sections.flatMap((s) => s.fields);
  const value = (key: string) => fields.find((f) => f.key === key)?.value ?? null;
  const nationalId = value("mainid") ?? record.national_id;
  // The note says identifiers are masked only when some are (FB-CIVIL-02G: each can then be revealed).
  const someMasked = [nationalId, ...record.links.map((l) => l.national_id ?? null)].some((v) => v?.includes("*"));
  // undefined = this level does not return the death date at all.
  const deathField = fields.find((f) => f.key === "deathdate");
  const age = recordAge(value("datebirth"), deathField ? deathField.value : undefined);

  const deceased = age.kind === "AT_DEATH" || (age.kind === "UNKNOWN" && age.deceased);
  const label = (key: string) => (key === "age" && deceased ? "العمر عند الوفاة" : (FIELD_LABELS[key] ?? key));
  const display = (key: string, raw: string | null): string | null => {
    if (key === "age") return age.kind === "UNKNOWN" ? null : `${age.years.toLocaleString("ar")} سنة`;
    if (DATE_FIELDS.has(key)) return formatCivilDate(raw);
    return raw;
  };

  return (
    <div className="flex flex-col gap-4" data-citizen-detail data-level={record.level}>
      <Panel data-record-header>
        <div className="flex items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
            <IdCard className="size-5" />
          </span>
          <div className="flex min-w-0 flex-1 flex-col gap-1">
            <h2 className="text-lg leading-snug font-bold break-words text-foreground">
              <span data-field="fullname">{value("fullname") ?? record.fullname ?? MISSING}</span>
            </h2>
            <p className="flex flex-wrap items-baseline gap-x-2 text-sm">
              <span className="text-muted-foreground">رقم الهوية</span>
              {nationalId ? (
                <CivilLookupId
                  value={nationalId}
                  revealRef={record.ref}
                  space={space}
                  canReveal={canReveal}
                  className="font-semibold tabular-nums"
                  field="mainid"
                />
              ) : (
                <span className="text-subtle-foreground" data-field="mainid">
                  {MISSING}
                </span>
              )}
            </p>
          </div>
        </div>
        <p className="mt-3 text-[13px] text-muted-foreground" data-source-note>
          تُعرض القيم كما وردت في الإصدار المنشور من السجل المدني، دون تفسير للرموز. العمر محسوب من تاريخ الميلاد.
          {record.level === "STANDARD" &&
            " يعرض هذا المستوى حقولًا محددة. رقم هوية هذا السجل كامل، وأرقام الأشخاص المرتبطين مخفية جزئيًا. أول عرض لسجل في اليوم يُحتسب من رصيدك، وإعادة فتحه في اليوم نفسه مجانية."}
          {record.level === "EXTENDED_CONTROLLED" &&
            ` تُعرض بيانات الهوية والحالة الاجتماعية والمكان فقط${someMasked ? "، وتظهر أرقام الهوية مخفية جزئيًا" : ""}.`}
          {someMasked &&
            canReveal &&
            " لإظهار رقم كامل اضغط رمز العين بجانبه؛ أول إظهار لسجل في اليوم يُحتسب من رصيدك، وإعادة إظهاره مجانية."}
        </p>
      </Panel>

      {record.sections.map((section) => {
        const shown = section.fields.filter((f) => !HEADER_FIELDS.has(f.key));
        if (shown.length === 0) return null;
        return (
          <Panel key={section.key} data-section={section.key}>
            <SectionHeader title={SECTION_LABELS[section.key] ?? section.key} className="mb-2" />
            <dl className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
              {shown.map((field) => {
                const text = display(field.key, field.value);
                return (
                  <div key={field.key} className="flex min-w-0 flex-col gap-0.5 border-b border-border/70 py-2.5 last:border-0">
                    <dt className="text-[13px] text-muted-foreground">{label(field.key)}</dt>
                    <dd className={text === null ? "text-sm text-subtle-foreground" : "min-w-0 text-sm font-medium break-words"}>
                      {text !== null && IDENTIFIER_FIELDS.has(field.key) ? (
                        <bdi dir="ltr" className="tabular-nums" data-field={field.key}>
                          {text}
                        </bdi>
                      ) : (
                        <span data-field={field.key} data-age-kind={field.key === "age" ? age.kind : undefined}>
                          {text ?? MISSING}
                        </span>
                      )}
                      {field.key === "age" && age.kind === "LIVING" && !age.deathKnown && (
                        <span className="mt-0.5 block text-xs font-normal text-muted-foreground" data-age-note>
                          محسوب حتى اليوم؛ تاريخ الوفاة لا يُعرض في هذا المستوى.
                        </span>
                      )}
                    </dd>
                  </div>
                );
              })}
            </dl>
          </Panel>
        );
      })}

      <Panel data-section="links">
        <SectionHeader
          title="الأشخاص المرتبطون"
          description="فتح سجل شخص مرتبط استعلام جديد يُحتسب من رصيدك. الاشتراك في رقم الأسرة لا يعني صلة أبوة أو زواج."
          icon={Users}
          className="mb-2"
        />
        <ul className="flex flex-col divide-y divide-border/70">
          {record.links.map((link) => {
            const opening = openingLink === link.relation;
            const reverse = link.relation === "HOUSEHOLD" || link.relation === "CHILDREN";
            return (
              <li
                key={link.relation}
                className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                data-link={link.relation}
                data-link-status={link.status}
              >
                <span className="flex min-w-0 flex-col">
                  <span className="text-sm font-medium">{RELATION_LABELS[link.relation]}</span>
                  {link.national_id && (
                    <span className="text-[13px] text-muted-foreground">
                      <CivilLookupId
                        value={link.national_id}
                        revealRef={link.status === "AVAILABLE" ? link.ref : undefined}
                        space={space}
                        canReveal={canReveal}
                        subject={RELATION_LABELS[link.relation]}
                      />
                    </span>
                  )}
                </span>
                {link.status === "AVAILABLE" && link.ref ? (
                  <Button
                    variant="outline"
                    className="h-11 w-full sm:h-9 sm:w-auto"
                    disabled={busy}
                    aria-busy={opening || undefined}
                    onClick={() => (reverse ? onOpenLinks(link, link.relation as CivilLookupReverseRelation) : onOpenLink(link))}
                  >
                    {reverse ? <Users className="size-3.5" aria-hidden /> : <Link2 className="size-3.5" aria-hidden />}
                    {opening ? "جارٍ الفتح..." : reverse ? "عرض القائمة" : "فتح السجل"}
                  </Button>
                ) : (
                  link.status !== "AVAILABLE" && (
                    <span className="inline-flex">
                      <StatusBadge tone="neutral">{LINK_STATUS_LABELS[link.status]}</StatusBadge>
                    </span>
                  )
                )}
              </li>
            );
          })}
        </ul>
      </Panel>
    </div>
  );
}
