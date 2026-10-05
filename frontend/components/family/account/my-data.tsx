"use client";

import Link from "next/link";
import { AlertCircle, ArrowRight, Info, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { SensitiveValue } from "@/components/family/account/sensitive-value";
import { ageText } from "@/components/family/members/member-card";
import { isAccessFailure } from "@/lib/api/family-household";
import { type FamilySelf, type SelfRevealField, useFamilySelfQuery } from "@/lib/api/family-self";
import { formatDateLong } from "@/lib/utils/date";
import { maritalStatusLabels } from "@/lib/utils/marital-status";
import { relationshipLabel } from "@/lib/utils/relationship";

// Canonical vocabulary (docs/11 §23a): a missing value is «غير مسجّل»; an
// explicit UNKNOWN enum is «غير معروف» (via maritalStatusLabels).
export const NOT_RECORDED = "غير مسجّل";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

function Missing() {
  return <span className="font-normal text-subtle-foreground">{NOT_RECORDED}</span>;
}

/** One label/value row. */
function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

/**
 * A sensitive value: the server's mask with its own Eye control (PWA-3B.2),
 * or «غير مسجّل» — and then no control — when nothing is recorded.
 */
function Sensitive({ field, masked }: { field: SelfRevealField; masked: string | null }) {
  if (masked === null) return <Missing />;

  return <SensitiveValue field={field} masked={masked} />;
}

function Section({ id, title, children }: { id: string; title: string; children: React.ReactNode }) {
  return (
    <section className={card} aria-labelledby={`my-data-${id}-title`} data-my-data-section={id}>
      <h2 id={`my-data-${id}-title`} className="text-base font-semibold text-foreground">
        {title}
      </h2>
      <dl className="mt-1 divide-y divide-stroke-subtle">{children}</dl>
    </section>
  );
}

const genderLabels = { MALE: "ذكر", FEMALE: "أنثى" } as const;

function MyDataSections({ self }: { self: FamilySelf }) {
  return (
    <div className="flex flex-col gap-4">
      <Section id="basic" title="البيانات الأساسية">
        <Row label="الاسم الكامل" field="full_name">
          <bdi>{self.full_name}</bdi>
        </Row>
        <Row label="رقم الهوية" field="national_id">
          <Sensitive field="NATIONAL_ID" masked={self.national_id_masked} />
        </Row>
        <Row label="الجنس" field="gender">
          {self.gender ? genderLabels[self.gender] : <Missing />}
        </Row>
        <Row label="تاريخ الميلاد" field="birth_date">
          {self.birth_date ? <span className="tabular-nums">{formatDateLong(self.birth_date)}</span> : <Missing />}
        </Row>
        {self.birth_date && (
          // Not applicable without a birth date: the row is omitted.
          <Row label="العمر" field="age">
            <span className="tabular-nums">{ageText(self.birth_date)}</span>
          </Row>
        )}
        <Row label="الحالة الاجتماعية" field="marital_status">
          {self.marital_status ? maritalStatusLabels[self.marital_status] : <Missing />}
        </Row>
      </Section>

      <Section id="contact" title="بيانات الاتصال">
        <Row label="رقم الجوال" field="mobile">
          <Sensitive field="MOBILE" masked={self.mobile_masked} />
        </Row>
        <Row label="الجوال البديل" field="alternate_mobile">
          <Sensitive field="ALTERNATE_MOBILE" masked={self.alternate_mobile_masked} />
        </Row>
        {self.alternate_mobile_masked !== null && (
          // Describes the alternate number; not applicable without one.
          <Row label="صلة صاحب الجوال البديل" field="alternate_mobile_owner_relation">
            {self.alternate_mobile_owner_relation ? <bdi>{self.alternate_mobile_owner_relation}</bdi> : <Missing />}
          </Row>
        )}
      </Section>

      <Section id="membership" title="بيانات العضوية">
        <Row label="الصلة بالأسرة" field="relationship">
          {self.relationship ? relationshipLabel(self.relationship, self.gender) : <Missing />}
        </Row>
        {self.is_household_head && (
          <Row label="الدور في الأسرة" field="household_head">
            <span className="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-800">رب الأسرة</span>
          </Row>
        )}
        <Row label="تاريخ بدء العضوية" field="membership_started_at">
          {self.membership_started_at ? (
            <span className="tabular-nums">{formatDateLong(self.membership_started_at)}</span>
          ) : (
            <Missing />
          )}
        </Row>
      </Section>

      <p className="flex items-start gap-2 px-1 text-[13px] leading-relaxed text-muted-foreground" data-my-data-note>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        هذه هي البيانات المسجّلة حاليًا في سجل الأسرة. تُعرض أرقام الهوية والجوال مخفية جزئيًا لحماية خصوصيتك على الشاشة؛ اضغط زر الإظهار لعرض الرقم كاملًا مؤقتًا.
      </p>
    </div>
  );
}

function MyDataSkeleton() {
  return (
    <div className="flex flex-col gap-4" data-my-data-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل بياناتك الشخصية
      </p>
      {[6, 3, 3].map((rows, i) => (
        <div key={i} className={card} aria-hidden>
          <Skeleton className="h-5 w-32" />
          {Array.from({ length: rows }, (_, r) => (
            <div key={r} className="mt-3 flex items-center justify-between gap-4">
              <Skeleton className="h-4 w-24" />
              <Skeleton className="h-4 w-32" />
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

function MyDataError({ onRetry, retrying }: { onRetry: () => void; retrying: boolean }) {
  return (
    <section className={`${card} flex flex-col items-center gap-3 py-6 text-center`} data-my-data-error>
      <AlertCircle className="size-6 text-danger" aria-hidden />
      <p className="text-sm font-medium text-foreground" role="alert">
        تعذّر تحميل بياناتك الشخصية
      </p>
      <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
        <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
        إعادة المحاولة
      </Button>
    </section>
  );
}

/**
 * بياناتي الشخصية (PWA-3B.1 / 3B.2, docs/11 §23a): the signed-in household
 * head's own registry data, read-only. Sensitive values are shown masked as
 * received, each with its own temporary reveal (SensitiveValue); there is no
 * edit. A 401 or 403 is
 * handled by the session/access flow (the gate and the shell), any other
 * failure inline.
 */
export function MyData() {
  const query = useFamilySelfQuery();

  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          href="/family/members"
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
          data-my-data-back
        >
          <ArrowRight className="size-4" aria-hidden />
          أفراد الأسرة
        </Link>
        <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">بياناتي الشخصية</h1>
      </header>

      <div aria-busy={query.isPending} data-my-data>
        {query.data ? (
          <MyDataSections self={query.data} />
        ) : query.isError ? (
          !isAccessFailure(query.error) && <MyDataError onRetry={() => query.refetch()} retrying={query.isFetching} />
        ) : (
          <MyDataSkeleton />
        )}
      </div>
    </div>
  );
}
