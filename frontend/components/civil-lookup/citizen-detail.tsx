"use client";

import { Link2, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { DetailItem, DetailList, Panel, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import type { CivilLookupLink, CivilLookupRecord, CivilLookupReverseRelation } from "@/lib/types/api/civil-lookup";
import { FIELD_LABELS, LINK_STATUS_LABELS, MISSING, RELATION_LABELS, SECTION_LABELS } from "./labels";

const IDENTIFIER_FIELDS = new Set(["mainid", "fatherid", "motherid", "partnerid", "householdid", "phonenum"]);

/**
 * One citizen of the active published release (FB-CIVIL-02A, ADR §L-4, §L-8):
 * the sections and fields the API returned for this user's level, raw values
 * ("غير متوفر" when empty), and the linked citizens. Opening a linked citizen
 * or a household / children list is a new, counted lookup.
 */
export function CitizenDetail({
  record,
  busy,
  onOpenLink,
  onOpenLinks,
}: {
  record: CivilLookupRecord;
  busy: boolean;
  onOpenLink: (link: CivilLookupLink) => void;
  onOpenLinks: (link: CivilLookupLink, relation: CivilLookupReverseRelation) => void;
}) {
  return (
    <div className="flex flex-col gap-4" data-citizen-detail data-level={record.level}>
      <p className="text-[13px] text-muted-foreground" data-source-note>
        تُعرض القيم كما وردت في الإصدار المنشور من السجل المدني، دون تفسير للرموز.
        {record.level === "STANDARD" && " يعرض هذا المستوى حقولًا محددة، وتظهر أرقام الهوية مخفية جزئيًا."}
        {record.level === "EXTENDED_CONTROLLED" && " تُعرض بيانات الهوية والحالة الاجتماعية والمكان فقط، وتظهر أرقام الهوية مخفية جزئيًا."}
      </p>
      {record.sections.map((section) => (
        <Panel key={section.key} data-section={section.key}>
          <SectionHeader title={SECTION_LABELS[section.key] ?? section.key} className="mb-2" />
          <DetailList>
            {section.fields.map((field) => (
              <DetailItem
                key={field.key}
                label={FIELD_LABELS[field.key] ?? field.key}
                ltr={field.value !== null && IDENTIFIER_FIELDS.has(field.key)}
                muted={field.value === null}
              >
                <span data-field={field.key}>{field.value ?? MISSING}</span>
              </DetailItem>
            ))}
          </DetailList>
        </Panel>
      ))}

      <Panel data-section="links">
        <SectionHeader
          title="الأشخاص المرتبطون"
          description="فتح سجل شخص مرتبط استعلام جديد يُحتسب من رصيدك. الاشتراك في رقم الأسرة لا يعني صلة أبوة أو زواج."
          icon={Users}
          className="mb-2"
        />
        <ul className="flex flex-col divide-y divide-border/70">
          {record.links.map((link) => (
            <li key={link.relation} className="flex flex-wrap items-center justify-between gap-2 py-2.5" data-link={link.relation} data-link-status={link.status}>
              <span className="text-sm font-medium">{RELATION_LABELS[link.relation]}</span>
              <span className="flex flex-wrap items-center gap-2 text-sm">
                {link.national_id && (
                  <bdi dir="ltr" className="tabular-nums">
                    {link.national_id}
                  </bdi>
                )}
                {link.status === "AVAILABLE" && link.ref ? (
                  link.relation === "HOUSEHOLD" || link.relation === "CHILDREN" ? (
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => onOpenLinks(link, link.relation as CivilLookupReverseRelation)}>
                      <Users className="size-3.5" aria-hidden />
                      عرض القائمة
                    </Button>
                  ) : (
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => onOpenLink(link)}>
                      <Link2 className="size-3.5" aria-hidden />
                      فتح السجل
                    </Button>
                  )
                ) : (
                  link.status !== "AVAILABLE" && <StatusBadge tone="neutral">{LINK_STATUS_LABELS[link.status]}</StatusBadge>
                )}
              </span>
            </li>
          ))}
        </ul>
      </Panel>
    </div>
  );
}
