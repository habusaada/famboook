"use client";

import { X } from "lucide-react";
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { ageText } from "@/components/family/members/member-card";
import type { FamilyMember } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import {
  DEATH_DATE_UNKNOWN,
  NOT_RECORDED,
  genderLabels,
  lifeStatusText,
} from "@/lib/utils/family-portal-labels";
import { maritalStatusLabels } from "@/lib/utils/marital-status";
import { relationshipLabel } from "@/lib/utils/relationship";

function Missing({ children = NOT_RECORDED }: { children?: string }) {
  return <span className="font-normal text-subtle-foreground">{children}</span>;
}

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function Group({ id, title, children }: { id: string; title: string; children: React.ReactNode }) {
  return (
    <section aria-labelledby={`member-${id}-title`} data-member-section={id}>
      <h3 id={`member-${id}-title`} className="text-sm font-semibold text-foreground">
        {title}
      </h3>
      <dl className="mt-0.5 divide-y divide-stroke-subtle">{children}</dl>
    </section>
  );
}

/** A masked registry value exactly as received; the member reveal is PWA-3B.4. */
function Masked({ value }: { value: string | null }) {
  if (value === null) return <Missing />;

  return (
    <bdi dir="ltr" className="font-mono tracking-wide tabular-nums" data-masked>
      {value}
    </bdi>
  );
}

function DateValue({ value }: { value: string | null }) {
  return value ? <span className="tabular-nums">{formatDateLong(value)}</span> : <Missing />;
}

/**
 * The registry details of ONE household member (PWA-3B.3, docs/11 §23a), in
 * an in-page sheet fed by the member list — no member identifier, no URL and
 * no extra request. Read-only. The National ID and mobiles are shown MASKED
 * only, with no reveal control: revealing a member's value is PWA-3B.4
 * (docs/11 FU-13). The head's own reveal stays on «بياناتي الشخصية».
 */
export function MemberDetailSheet({ member, onClose }: { member: FamilyMember | null; onClose: () => void }) {
  return (
    <Sheet open={member !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side="bottom"
        showCloseButton={false}
        className="mx-auto max-h-[85dvh] max-w-md gap-0 overflow-y-auto rounded-t-2xl pb-[env(safe-area-inset-bottom)]"
        data-member-detail
      >
        {member && <Details member={member} />}
      </SheetContent>
    </Sheet>
  );
}

function Details({ member }: { member: FamilyMember }) {
  const relationship = relationshipLabel(member.relationship, member.gender);
  const deceased = member.life_status === "DECEASED";

  return (
    <>
      <SheetHeader className="flex-row items-start justify-between gap-3 border-b border-border">
        <div className="min-w-0">
          <SheetTitle className="text-lg font-bold">
            <bdi>{member.full_name}</bdi>
          </SheetTitle>
          <SheetDescription>تفاصيل الفرد المسجّلة في سجل الأسرة</SheetDescription>
        </div>
        <SheetClose
          aria-label="إغلاق"
          className="flex size-10 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
        >
          <X className="size-5" aria-hidden />
        </SheetClose>
      </SheetHeader>

      <div className="flex flex-col gap-5 p-4">
        <Group id="basic" title="البيانات الأساسية">
          <Row label="الاسم" field="full_name">
            <bdi>{member.full_name}</bdi>
          </Row>
          <Row label="الصلة" field="relationship">
            {member.relationship ? relationship : <Missing />}
          </Row>
          {member.is_household_head && (
            <Row label="الدور في الأسرة" field="household_head">
              <span className="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-800">رب الأسرة</span>
            </Row>
          )}
          <Row label="الجنس" field="gender">
            {member.gender ? genderLabels[member.gender] : <Missing />}
          </Row>
          <Row label="تاريخ الميلاد" field="birth_date">
            <DateValue value={member.birth_date} />
          </Row>
          {member.birth_date && member.life_status === "ALIVE" && (
            // A current age only for a living member with a recorded birth date.
            <Row label="العمر" field="age">
              <span className="tabular-nums">{ageText(member.birth_date)}</span>
            </Row>
          )}
          <Row label="الحالة الاجتماعية" field="marital_status">
            {member.marital_status ? maritalStatusLabels[member.marital_status] : <Missing />}
          </Row>
          <Row label="حالة الفرد" field="life_status">
            {member.life_status ? lifeStatusText(member.life_status, member.gender) : <Missing />}
          </Row>
          {deceased && (
            <Row label="تاريخ الوفاة" field="death_date">
              {member.death_date ? (
                <span className="tabular-nums">{formatDateLong(member.death_date)}</span>
              ) : (
                <Missing>{DEATH_DATE_UNKNOWN}</Missing>
              )}
            </Row>
          )}
        </Group>

        <Group id="identity" title="بيانات الهوية والاتصال">
          <Row label="رقم الهوية" field="national_id">
            <Masked value={member.national_id_masked} />
          </Row>
          <Row label="رقم الجوال" field="mobile">
            <Masked value={member.mobile_masked} />
          </Row>
          <Row label="الجوال البديل" field="alternate_mobile">
            <Masked value={member.alternate_mobile_masked} />
          </Row>
          {member.alternate_mobile_masked !== null && (
            <Row label="صلة صاحب الجوال البديل" field="alternate_mobile_owner_relation">
              {member.alternate_mobile_owner_relation ? <bdi>{member.alternate_mobile_owner_relation}</bdi> : <Missing />}
            </Row>
          )}
        </Group>

        <Group id="membership" title="بيانات العضوية">
          <Row label="تاريخ بدء العضوية" field="membership_started_at">
            <DateValue value={member.membership_started_at} />
          </Row>
        </Group>
      </div>
    </>
  );
}
