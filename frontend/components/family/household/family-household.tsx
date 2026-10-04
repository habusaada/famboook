"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, RotateCw, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { SUMMARY_CAPTION } from "@/components/family/home/household-cards";
import { type FamilyProfile, isAccessFailure, useFamilyProfileQuery } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { displacementStatusLabel } from "@/lib/utils/displacement";

export const NOT_RECORDED = "غير مسجّل";
export const NO_RESIDENCE = "لا توجد بيانات سكن مسجّلة";
export const NO_CURRENT_ADDRESS = "لم يُسجَّل عنوان السكن الحالي";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

/** One label/value row. */
function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function FamilyInfo({ family }: { family: FamilyProfile["family"] }) {
  return (
    <section className={card} aria-labelledby="household-info-title" data-household-info>
      <h2 id="household-info-title" className="text-base font-semibold text-foreground">
        معلومات الأسرة
      </h2>
      <dl className="mt-1 divide-y divide-stroke-subtle">
        <Row label="رمز الأسرة" field="family_code">
          <span dir="ltr" className="font-mono">
            {family.family_code}
          </span>
        </Row>
        <Row label="العائلة" field="clan">
          {family.clan_name ? <bdi>{family.clan_name}</bdi> : NOT_RECORDED}
        </Row>
        {family.branch_name && (
          <Row label="الفرع" field="branch">
            <bdi>{family.branch_name}</bdi>
          </Row>
        )}
        <Row label="رب الأسرة" field="head">
          <bdi>{family.head.full_name}</bdi>
        </Row>
        <Row label="عدد أفراد الأسرة حسب الإقرار" field="declared_household_size">
          {family.declared_household_size === null ? (
            <span className="text-subtle-foreground">غير مُعلن</span>
          ) : (
            <span className="tabular-nums">{family.declared_household_size}</span>
          )}
        </Row>
        {family.declared_at && (
          <Row label="تاريخ الإقرار" field="declared_at">
            <span className="tabular-nums">{formatDateLong(family.declared_at)}</span>
          </Row>
        )}
        <Row label="الأفراد المسجّلون بالتفصيل" field="registered_member_count">
          <span className="tabular-nums">{family.registered_member_count}</span>
        </Row>
      </dl>
      <p className="mt-2 text-xs leading-relaxed text-subtle-foreground" data-count-caption>
        {SUMMARY_CAPTION}
      </p>
    </section>
  );
}

/** The populated parts of the current address, most local first; never an empty label. */
function currentAddressParts(address: NonNullable<FamilyProfile["residence"]>["current_address"]): string[] {
  return [address.neighborhood, address.area, address.city, address.governorate].filter(
    (part): part is string => part !== null && part.trim() !== ""
  );
}

function Residence({ residence }: { residence: FamilyProfile["residence"] }) {
  const parts = residence ? currentAddressParts(residence.current_address) : [];

  return (
    <section className={card} aria-labelledby="household-residence-title" data-household-residence>
      <h2 id="household-residence-title" className="text-base font-semibold text-foreground">
        السكن
      </h2>
      {residence === null ? (
        <p className="mt-3 text-sm text-muted-foreground" data-no-residence>
          {NO_RESIDENCE}
        </p>
      ) : (
        <dl className="mt-1 divide-y divide-stroke-subtle">
          <Row label="السكن الأصلي" field="original_residence">
            {residence.original_residence_text?.trim() ? <bdi>{residence.original_residence_text}</bdi> : NOT_RECORDED}
          </Row>
          <Row label="حالة النزوح" field="displacement_status">
            {displacementStatusLabel(residence.displacement_status)}
          </Row>
          {residence.displacement_status === "DISPLACED" && (
            <Row label="مكان النزوح الحالي" field="displacement_location">
              {residence.displacement_location_text?.trim() ? <bdi>{residence.displacement_location_text}</bdi> : NOT_RECORDED}
            </Row>
          )}
          <Row label="العنوان الحالي" field="current_address">
            {parts.length === 0 ? (
              <span className="font-normal text-muted-foreground">{NO_CURRENT_ADDRESS}</span>
            ) : (
              parts.map((part, i) => (
                <span key={i} data-address-part>
                  {i > 0 && "، "}
                  <bdi>{part}</bdi>
                </span>
              ))
            )}
          </Row>
        </dl>
      )}
    </section>
  );
}

function MembersEntry({ count }: { count: number }) {
  return (
    <section className={card} aria-labelledby="household-members-title" data-household-members>
      <h2 id="household-members-title" className="text-base font-semibold text-foreground">
        أفراد الأسرة
      </h2>
      <p className="mt-1 text-sm text-muted-foreground" data-members-count>
        أفراد الأسرة المسجلون (<span className="tabular-nums">{count}</span>)
      </p>
      <Link
        href="/family/members"
        className="mt-3 flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-border px-3.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        data-members-link
      >
        <span className="flex items-center gap-2">
          <UsersRound className="size-4" aria-hidden />
          عرض أفراد الأسرة
        </span>
        <ChevronLeft className="size-4" aria-hidden />
      </Link>
    </section>
  );
}

function HouseholdSkeleton() {
  return (
    <div className="flex flex-col gap-4" data-household-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل بيانات الأسرة
      </p>
      {[7, 4, 2].map((rows, i) => (
        <div key={i} className={card} aria-hidden>
          <Skeleton className="h-5 w-28" />
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

function HouseholdError({ onRetry, retrying }: { onRetry: () => void; retrying: boolean }) {
  return (
    <section className={`${card} flex flex-col items-center gap-3 py-6 text-center`} data-household-error>
      <AlertCircle className="size-6 text-danger" aria-hidden />
      <p className="text-sm font-medium text-foreground" role="alert">
        تعذّر تحميل بيانات الأسرة
      </p>
      <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
        <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
        إعادة المحاولة
      </Button>
    </section>
  );
}

/**
 * أسرتي (PWA-3A Step 4): the household's official family facts and current
 * residence, read-only, and the way to the members. Values are shown as
 * recorded; missing ones are labelled, never inferred. A 401 or 403 is handled
 * by the session/access flow (the gate and the shell), any other failure
 * inline.
 */
export function FamilyHousehold() {
  const query = useFamilyProfileQuery();
  const data = query.data;

  return (
    <div className="flex flex-col gap-5">
      <header className="flex items-center justify-between gap-3">
        <h1 className="text-2xl leading-snug font-bold text-foreground">أسرتي</h1>
        {data && (
          <span dir="ltr" className="shrink-0 rounded-lg bg-brand-50 px-2.5 py-1 font-mono text-[13px] font-medium text-brand-800" data-family-code>
            {data.family.family_code}
          </span>
        )}
      </header>

      <div aria-busy={query.isPending} data-household>
        {data ? (
          <div className="flex flex-col gap-4">
            <FamilyInfo family={data.family} />
            <Residence residence={data.residence} />
            <MembersEntry count={data.family.registered_member_count} />
          </div>
        ) : query.isError ? (
          !isAccessFailure(query.error) && <HouseholdError onRetry={() => query.refetch()} retrying={query.isFetching} />
        ) : (
          <HouseholdSkeleton />
        )}
      </div>
    </div>
  );
}
