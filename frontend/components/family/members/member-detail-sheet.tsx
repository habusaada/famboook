"use client";

import Link from "next/link";
import { ChevronLeft, IdCard, X } from "lucide-react";
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { SensitiveValue } from "@/components/family/account/sensitive-value";
import { ageText } from "@/components/family/members/member-card";
import { MemberHealth } from "@/components/family/members/member-health";
import type { FamilyMember } from "@/lib/api/family-household";
import { revealMemberValue } from "@/lib/api/family-member-reveal";
import type { SelfRevealField } from "@/lib/api/family-self";
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

/** A masked registry value exactly as received, with no reveal control. */
function Masked({ value }: { value: string | null }) {
  if (value === null) return <Missing />;

  return (
    <bdi dir="ltr" className="font-mono tracking-wide tabular-nums" data-masked>
      {value}
    </bdi>
  );
}

/**
 * A member's sensitive value (PWA-3B.4): the mask with its own Eye control
 * for ANOTHER member, revealed through the member reveal by member_ref; the
 * head's own values are masked only here (their reveal is «بياناتي الشخصية»).
 * No control when nothing is recorded. The full value lives only in the
 * control's state, keyed by member_ref, so closing the sheet or switching
 * member discards it.
 */
function MemberSensitive({ member, field, masked }: { member: FamilyMember; field: SelfRevealField; masked: string | null }) {
  if (masked === null) return <Missing />;
  if (member.is_household_head) return <Masked value={masked} />;

  return (
    <SensitiveValue
      key={`${member.member_ref}:${field}`}
      field={field}
      masked={masked}
      reveal={() => revealMemberValue(member.member_ref, field)}
    />
  );
}

function DateValue({ value }: { value: string | null }) {
  return value ? <span className="tabular-nums">{formatDateLong(value)}</span> : <Missing />;
}

/**
 * The registry details of ONE household member (PWA-3B.3, docs/11 §23a), in
 * an in-page sheet fed by the member list — no member identifier, no URL and
 * no extra request. Read-only. The National ID and mobiles are shown masked;
 * another member's can be revealed field by field (PWA-3B.4); the head's own
 * are revealed only on «بياناتي الشخصية» (a link here).
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
        {/* Keyed by member: another member — or a closed sheet — discards every revealed value. */}
        {member && <Details key={member.member_ref} member={member} />}
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

        {/* Health (PWA-3B.6): this member's records only, by member_ref. */}
        <MemberHealth memberRef={member.member_ref} />

        <Group id="identity" title="بيانات الهوية والاتصال">
          <Row label="رقم الهوية" field="national_id">
            <MemberSensitive member={member} field="NATIONAL_ID" masked={member.national_id_masked} />
          </Row>
          <Row label="رقم الجوال" field="mobile">
            <MemberSensitive member={member} field="MOBILE" masked={member.mobile_masked} />
          </Row>
          <Row label="الجوال البديل" field="alternate_mobile">
            <MemberSensitive member={member} field="ALTERNATE_MOBILE" masked={member.alternate_mobile_masked} />
          </Row>
          {member.alternate_mobile_masked !== null && (
            <Row label="صلة صاحب الجوال البديل" field="alternate_mobile_owner_relation">
              {member.alternate_mobile_owner_relation ? <bdi>{member.alternate_mobile_owner_relation}</bdi> : <Missing />}
            </Row>
          )}
          {member.is_household_head && (
            // The head's own values: revealed only on «بياناتي الشخصية».
            <Link
              href="/family/account/me"
              className="mt-2 flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-border px-3.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
              data-my-data-link
            >
              <span className="flex items-center gap-2">
                <IdCard className="size-4" aria-hidden />
                لإظهار بياناتك استخدم «بياناتي الشخصية»
              </span>
              <ChevronLeft className="size-4" aria-hidden />
            </Link>
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
