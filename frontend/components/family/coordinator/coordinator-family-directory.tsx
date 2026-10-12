"use client";

import { useState } from "react";
import Link from "next/link";
import { ChevronRight, Loader2, Search, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { fieldClass } from "@/components/family/auth/auth-parts";
import { SensitiveValue } from "@/components/family/account/sensitive-value";
import { CoordinatorFamilySummary } from "@/components/family/coordinator/coordinator-family-summary";
import {
  type CoordinatorFamilyDetail,
  type CoordinatorMember,
  type CoordinatorRevealField,
  revealCoordinatorMemberValue,
  useCoordinatorDirectoryQuery,
} from "@/lib/api/coordinator";
import { formatDateLong } from "@/lib/utils/date";
import { NOT_DECLARED, NOT_RECORDED, genderLabels, lifeStatusText } from "@/lib/utils/family-portal-labels";
import { maritalStatusLabels } from "@/lib/utils/marital-status";
import { relationshipLabel } from "@/lib/utils/relationship";

const PAGE_SIZE = 10;

const BACK = (
  <Link href="/family/coordinator" className="inline-flex min-h-10 items-center gap-1 rounded-md text-sm font-semibold text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
    <ChevronRight className="size-4" aria-hidden />
    العودة إلى قائمة الأسر
  </Link>
);

function Missing({ children = NOT_RECORDED }: { children?: string }) {
  return <span className="font-normal text-subtle-foreground">{children}</span>;
}

/** Whole years as the household views word them; deceased: no current age. */
function ageLabel(member: CoordinatorMember): React.ReactNode {
  if (member.age === null) return member.life_status === "DECEASED" ? <Missing>—</Missing> : <Missing />;
  if (member.age < 1) return "أقل من سنة";
  if (member.age === 1) return "سنة واحدة";
  if (member.age === 2) return "سنتان";
  if (member.age <= 10) return `${member.age} سنوات`;
  return `${member.age} سنة`;
}

/** Masked by default; a separate, audited reveal per field when allowed. */
function Identifier({
  code,
  member,
  field,
  canReveal,
}: {
  code: string;
  member: CoordinatorMember;
  field: CoordinatorRevealField;
  canReveal: boolean;
}) {
  const masked = field === "NATIONAL_ID" ? member.national_id_masked : member.mobile_masked;
  if (masked === null) return <Missing />;
  if (!canReveal) {
    return (
      <bdi dir="ltr" className="font-mono tracking-wide tabular-nums" data-masked>
        {masked}
      </bdi>
    );
  }

  return (
    <SensitiveValue
      key={`${member.member_ref}:${field}`}
      field={field}
      masked={masked}
      subject={member.full_name ?? undefined}
      scope="coordinator"
      reveal={() => revealCoordinatorMemberValue(code, member.member_ref, field)}
    />
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 px-4 py-3.5">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function FamilyDetails({ family }: { family: CoordinatorFamilyDetail }) {
  return (
    <dl className="divide-y divide-stroke-subtle rounded-2xl border border-border bg-surface-1" data-coordinator-details>
      <Row label="رمز الأسرة">
        <span dir="ltr" className="font-mono">
          {family.family_code}
        </span>
      </Row>
      <Row label="رب الأسرة">{family.head_name ? <bdi>{family.head_name}</bdi> : <Missing />}</Row>
      <Row label="العشيرة">{family.clan_name ? <bdi>{family.clan_name}</bdi> : <Missing />}</Row>
      <Row label="مجموعة الفروع">{family.branch_group_name ? <bdi>{family.branch_group_name}</bdi> : <Missing>—</Missing>}</Row>
      <Row label="الفرع">{family.branch_name ? <bdi>{family.branch_name}</bdi> : <Missing>—</Missing>}</Row>
      <Row label="الأفراد المسجّلون">
        <span data-registered-count>{family.registered_member_count}</span>
      </Row>
      <Row label="حجم الأسرة المُعلن">
        {family.declared_household_size === null ? (
          <Missing>{NOT_DECLARED}</Missing>
        ) : (
          <span className="flex flex-col items-end" data-declared-size>
            {family.declared_household_size}
            {family.declared_at && <span className="text-xs font-normal text-muted-foreground">بتاريخ {formatDateLong(family.declared_at)}</span>}
          </span>
        )}
      </Row>
    </dl>
  );
}

function memberName(member: CoordinatorMember) {
  return member.available ? <bdi>{member.full_name ?? "—"}</bdi> : <Missing>بيانات هذا الفرد غير متاحة</Missing>;
}

function HeadBadge() {
  return <span className="rounded-md bg-brand-50 px-1.5 py-0.5 text-[11px] font-semibold text-brand-900">رب الأسرة</span>;
}

/** Mobile: one card per member. */
function MemberCard({ code, member, canReveal }: { code: string; member: CoordinatorMember; canReveal: boolean }) {
  return (
    <li className="rounded-2xl border border-border bg-surface-1" data-member-card>
      <div className="flex items-start justify-between gap-3 px-4 pt-3.5">
        <p className="min-w-0 text-[15px] leading-snug font-semibold break-words text-foreground">{memberName(member)}</p>
        {member.is_household_head && <HeadBadge />}
      </div>
      <dl className="divide-y divide-stroke-subtle">
        <Row label="صلة القرابة">{relationshipLabel(member.relationship, member.gender)}</Row>
        {member.available && (
          <>
            <Row label="الجنس">{member.gender ? genderLabels[member.gender] : <Missing />}</Row>
            <Row label="العمر">{ageLabel(member)}</Row>
            <Row label="الحالة الاجتماعية">{member.marital_status ? maritalStatusLabels[member.marital_status] : <Missing />}</Row>
            <Row label="الحالة">{member.life_status ? lifeStatusText(member.life_status, member.gender) : <Missing />}</Row>
            <Row label="رقم الهوية">
              <Identifier code={code} member={member} field="NATIONAL_ID" canReveal={canReveal} />
            </Row>
            <Row label="رقم الجوال">
              <Identifier code={code} member={member} field="MOBILE" canReveal={canReveal} />
            </Row>
          </>
        )}
      </dl>
    </li>
  );
}

/** Desktop: one table row per member. */
function MemberTable({ code, members, canReveal }: { code: string; members: CoordinatorMember[]; canReveal: boolean }) {
  const th = "px-3 py-2.5 text-start text-xs font-semibold text-muted-foreground";
  const td = "px-3 py-2.5 align-middle text-sm text-foreground";

  return (
    <div className="hidden overflow-x-auto rounded-2xl border border-border bg-surface-1 md:block">
      <table className="w-full" data-member-table>
        <thead className="border-b border-stroke-subtle">
          <tr>
            <th className={th}>الاسم</th>
            <th className={th}>صلة القرابة</th>
            <th className={th}>الجنس</th>
            <th className={th}>العمر</th>
            <th className={th}>الحالة الاجتماعية</th>
            <th className={th}>الحالة</th>
            <th className={th}>رقم الهوية</th>
            <th className={th}>رقم الجوال</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-stroke-subtle">
          {members.map((member) => (
            <tr key={member.member_ref} data-member-row>
              <td className={`${td} font-medium`}>
                <span className="flex flex-wrap items-center gap-2">
                  {memberName(member)}
                  {member.is_household_head && <HeadBadge />}
                </span>
              </td>
              <td className={td}>{relationshipLabel(member.relationship, member.gender)}</td>
              {member.available ? (
                <>
                  <td className={td}>{member.gender ? genderLabels[member.gender] : <Missing />}</td>
                  <td className={td}>{ageLabel(member)}</td>
                  <td className={td}>{member.marital_status ? maritalStatusLabels[member.marital_status] : <Missing />}</td>
                  <td className={td}>{member.life_status ? lifeStatusText(member.life_status, member.gender) : <Missing />}</td>
                  <td className={td}>
                    <Identifier code={code} member={member} field="NATIONAL_ID" canReveal={canReveal} />
                  </td>
                  <td className={td}>
                    <Identifier code={code} member={member} field="MOBILE" canReveal={canReveal} />
                  </td>
                </>
              ) : (
                <td className={td} colSpan={6} />
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function MembersDirectory({ code, members, canReveal }: { code: string; members: CoordinatorMember[]; canReveal: boolean }) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const term = search.trim();
  const filtered = term === "" ? members : members.filter((m) => m.available && (m.full_name ?? "").includes(term));
  const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const current = Math.min(page, pages);
  const shown = filtered.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);

  return (
    <section aria-labelledby="coordinator-members-title" className="flex flex-col gap-3" data-coordinator-members>
      <h2 id="coordinator-members-title" className="text-sm font-semibold text-foreground">
        أفراد الأسرة المسجّلون ({members.length})
      </h2>
      {canReveal && (
        <p className="flex items-start gap-2 text-xs leading-relaxed text-muted-foreground" data-reveal-notice>
          <ShieldCheck className="mt-0.5 size-4 shrink-0 text-brand-700" aria-hidden />
          رقم الهوية ورقم الجوال مخفيان افتراضيًا. كل إظهار لقيمة كاملة يُسجَّل باسمك.
        </p>
      )}

      {members.length > 0 && (
        <div role="search">
          <Label htmlFor="coordinator-member-search" className="sr-only">
            بحث باسم الفرد
          </Label>
          <div className="relative">
            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-subtle-foreground" aria-hidden />
            <Input
              id="coordinator-member-search"
              type="search"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                setPage(1);
              }}
              placeholder="بحث باسم الفرد"
              maxLength={100}
              className={`${fieldClass} ps-9`}
            />
          </div>
        </div>
      )}

      {members.length === 0 ? (
        <p className="rounded-xl border border-border bg-surface-1 px-4 py-6 text-center text-sm text-muted-foreground" data-members-empty>
          لا يوجد أفراد مسجّلون في هذه الأسرة.
        </p>
      ) : filtered.length === 0 ? (
        <p className="rounded-xl border border-border bg-surface-1 px-4 py-6 text-center text-sm text-muted-foreground" data-members-no-match>
          لا يوجد أفراد مطابقون.
        </p>
      ) : (
        <>
          <ul className="flex flex-col gap-3 md:hidden" aria-label="أفراد الأسرة">
            {shown.map((member) => (
              <MemberCard key={member.member_ref} code={code} member={member} canReveal={canReveal} />
            ))}
          </ul>
          <MemberTable code={code} members={shown} canReveal={canReveal} />
        </>
      )}

      {pages > 1 && (
        <nav aria-label="صفحات الأفراد" className="flex items-center justify-between gap-3">
          <Button variant="outline" className="h-11 rounded-xl px-4" disabled={current <= 1} onClick={() => setPage(current - 1)}>
            السابق
          </Button>
          <span className="text-sm text-muted-foreground">
            صفحة {current} من {pages}
          </span>
          <Button variant="outline" className="h-11 rounded-xl px-4" disabled={current >= pages} onClick={() => setPage(current + 1)}>
            التالي
          </Button>
        </nav>
      )}
    </section>
  );
}

/**
 * A Family in Coordinator Space (docs/11 §8, CO-01C, docs/06 AUTH-ADR-092):
 * the details and the members directory — basic fields, identifiers masked,
 * one audited reveal per field — of a Family in the Coordinator's scope,
 * never the user's own household. Without the directory permission the
 * page falls back to the summary. Out of scope and nonexistent look the same.
 */
export function CoordinatorFamilyDirectory({ code }: { code: string }) {
  const directory = useCoordinatorDirectoryQuery(code);

  if (directory.data === "forbidden") return <CoordinatorFamilySummary code={code} />;
  if (directory.isError) {
    return (
      <div className="flex flex-col gap-4">
        {BACK}
        <p className="rounded-2xl border border-border bg-surface-1 px-4 py-8 text-center text-sm text-muted-foreground" role="alert">
          تعذّر الاتصال بالخادم.
        </p>
      </div>
    );
  }
  if (directory.data === undefined) {
    return (
      <div className="flex justify-center py-16">
        <Loader2 className="size-6 animate-spin text-brand-700" aria-label="جارٍ تحميل بيانات الأسرة" />
      </div>
    );
  }
  if (directory.data === "missing") {
    return (
      <div className="flex flex-col gap-4">
        {BACK}
        <p className="rounded-2xl border border-border bg-surface-1 px-4 py-8 text-center text-sm text-muted-foreground" role="alert" data-coordinator-family-missing>
          الأسرة غير متاحة.
        </p>
      </div>
    );
  }

  const { family, members, can_reveal: canReveal } = directory.data;

  return (
    <div className="flex flex-col gap-5">
      {BACK}
      <div className="flex flex-col gap-2">
        <span
          className="inline-flex w-fit items-center gap-1.5 rounded-lg border border-brand-100 bg-brand-50 px-2.5 py-1 text-[12px] font-semibold text-brand-900"
          data-coordinator-marker
        >
          <ShieldCheck className="size-3.5" aria-hidden />
          مساحة التنسيق · أسرة ضمن نطاقك
        </span>
        <div>
          <p className="text-sm text-muted-foreground">بيانات الأسرة</p>
          <h1 className="mt-0.5 text-2xl leading-snug font-bold text-foreground">
            <bdi>{family.head_name ?? "—"}</bdi>
          </h1>
        </div>
      </div>
      <FamilyDetails family={family} />
      <MembersDirectory code={family.family_code} members={members} canReveal={canReveal} />
    </div>
  );
}
