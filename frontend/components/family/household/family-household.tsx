"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, HandHeart, RotateCw, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { SUMMARY_CAPTION } from "@/components/family/home/household-cards";
import { GAZA_LOCATION_LEVEL_LABELS } from "@/components/geography/gaza-location-selector";
import { StatusBadge } from "@/components/shared/status-badge";
import { type FamilyProfile, isAccessFailure, useFamilyHouseholdHealthQuery, useFamilyProfileQuery } from "@/lib/api/family-household";
import type { GazaLocationSummary } from "@/lib/types/api/gaza-location";
import { formatDateLong } from "@/lib/utils/date";
import {
  HEALTH_REGISTERED,
  NO_DECLARATION,
  NOT_DECLARED,
  declarationSourceLabels,
  familyDisplacementLabel,
} from "@/lib/utils/family-portal-labels";

export const NOT_RECORDED = "غير مسجّل";
export const NO_RESIDENCE = "لا توجد بيانات سكن مسجّلة";
export const NO_LOCATION = "لم يُحدَّد موقع جغرافي";
export const LANDMARK_TYPE_LABEL = "نوع المعلم";
/** Recorded displacement free text — never the approved current geography (FB-GEO-02 G2-7). */
export const DISPLACEMENT_TEXT_LABEL = "مكان النزوح (نص مسجّل سابقًا)";
export const DISPLACEMENT_TEXT_HISTORY_LABEL = "مكان النزوح السابق (نص تاريخي)";

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

function Missing({ children = NOT_RECORDED }: { children?: string }) {
  return <span className="font-normal text-subtle-foreground">{children}</span>;
}

/** Text as recorded, or «غير مسجّل» for a missing or blank value. */
function Text({ value }: { value: string | null }) {
  return value?.trim() ? <bdi>{value}</bdi> : <Missing />;
}

function DateValue({ value }: { value: string | null }) {
  return value ? <span className="tabular-nums">{formatDateLong(value)}</span> : <Missing />;
}

function FamilyInfo({ family }: { family: FamilyProfile["family"] }) {
  return (
    <section className={card} aria-labelledby="household-info-title" data-household-info>
      <h2 id="household-info-title" className="text-base font-semibold text-foreground">
        بيانات الأسرة
      </h2>
      <dl className="mt-1 divide-y divide-stroke-subtle">
        <Row label="رمز الأسرة" field="family_code">
          <span dir="ltr" className="font-mono">
            {family.family_code}
          </span>
        </Row>
        <Row label="العائلة" field="clan">
          <Text value={family.clan_name} />
        </Row>
        {family.branch_group_name && (
          <Row label="مجموعة الفروع" field="branch_group">
            <bdi>{family.branch_group_name}</bdi>
          </Row>
        )}
        {family.branch_name && (
          <Row label="الفرع" field="branch">
            <bdi>{family.branch_name}</bdi>
          </Row>
        )}
        <Row label="رب الأسرة" field="head">
          <bdi>{family.head.full_name}</bdi>
        </Row>
        <Row label="تاريخ التسجيل" field="registration_date">
          <DateValue value={family.registration_date} />
        </Row>
        {family.paper_form_no?.trim() && (
          <Row label="رقم الاستمارة الورقية" field="paper_form_no">
            <bdi dir="ltr" className="tabular-nums">
              {family.paper_form_no}
            </bdi>
          </Row>
        )}
        <Row label="الأفراد المسجّلون بالتفصيل" field="registered_member_count">
          <span className="tabular-nums">{family.registered_member_count}</span>
        </Row>
      </dl>
    </section>
  );
}

/** A declared count: 0 is a value; null was not declared by the source. */
function Declared({ value }: { value: number | null }) {
  return value === null ? <Missing>{NOT_DECLARED}</Missing> : <span className="tabular-nums">{value}</span>;
}

/**
 * The CURRENT household declaration: source-declared facts, shown as stated.
 * Nothing here is derived from, or compared with, the registered members.
 */
function Declaration({ declaration }: { declaration: FamilyProfile["declaration"] }) {
  return (
    <section className={card} aria-labelledby="household-declaration-title" data-household-declaration>
      <h2 id="household-declaration-title" className="text-base font-semibold text-foreground">
        الإقرار الأسري الحالي
      </h2>
      {declaration === null ? (
        <p className="mt-3 text-sm text-muted-foreground" data-no-declaration>
          {NO_DECLARATION}
        </p>
      ) : (
        <>
          <dl className="mt-1 divide-y divide-stroke-subtle">
            <Row label="عدد أفراد الأسرة حسب الإقرار" field="declared_household_size">
              <Declared value={declaration.declared_household_size} />
            </Row>
            <Row label="الأبناء الذكور" field="declared_living_sons">
              <Declared value={declaration.declared_living_sons} />
            </Row>
            <Row label="البنات" field="declared_living_daughters">
              <Declared value={declaration.declared_living_daughters} />
            </Row>
            <Row label="تاريخ الإقرار" field="declared_at">
              <DateValue value={declaration.declared_at} />
            </Row>
            <Row label="مصدر الإقرار" field="declaration_source">
              {declarationSourceLabels[declaration.source]}
            </Row>
          </dl>
          <p className="mt-2 text-xs leading-relaxed text-subtle-foreground" data-count-caption>
            {SUMMARY_CAPTION}
          </p>
        </>
      )}
    </section>
  );
}

/** The populated parts of the recorded (legacy) address text, most local first; never an empty label. */
function legacyAddressParts(address: NonNullable<FamilyProfile["residence"]>["current_address"]): string[] {
  return [address.neighborhood, address.area, address.city, address.governorate].filter(
    (part): part is string => part !== null && part.trim() !== ""
  );
}

function SubHeading({ id, children }: { id: string; children: string }) {
  return (
    <h3 id={id} className="mt-4 text-sm font-semibold text-foreground first:mt-2">
      {children}
    </h3>
  );
}

/**
 * A structured Gaza location: one compact labelled field per hierarchy level,
 * exactly as the API returns it (the ancestors, then the place itself) —
 * nothing added, merged or dropped. Two columns when they fit, one when
 * narrow; long names wrap. «لم يُحدَّد موقع جغرافي» when none is recorded.
 * A landmark's type, when the API provides it (family-safe, FB-GEO-03), is
 * one more tile «نوع المعلم»; the name itself is never changed.
 */
function LocationRow({ label, location, field }: { label: string; location: GazaLocationSummary | null | undefined; field: string }) {
  const levels = location ? [...location.path, { id: location.id, level: location.level, name: location.name, is_active: location.is_active }] : [];

  return (
    <div className="py-2.5" data-field={field}>
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-2 text-sm">
        {location ? (
          <dl className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,8.5rem),1fr))] gap-2" data-location-levels>
            {levels.map((item) => (
              <div key={item.id} className="min-w-0 rounded-lg border border-stroke-subtle bg-surface-2 px-3 py-2" data-location-level={item.level}>
                <dt className="text-xs text-muted-foreground">{GAZA_LOCATION_LEVEL_LABELS[item.level]}</dt>
                <dd className="mt-0.5 font-medium text-foreground wrap-anywhere">
                  <bdi>{item.name}</bdi>
                  {!item.is_active && (
                    <StatusBadge tone="warning" className="ms-1.5 align-middle">
                      غير مفعّلة
                    </StatusBadge>
                  )}
                </dd>
              </div>
            ))}
            {/* FB-GEO-03: the family-safe landmark type, as its own tile; absent → no tile. */}
            {location.level === "LANDMARK" && location.landmark_type?.trim() && (
              <div className="min-w-0 rounded-lg border border-stroke-subtle bg-surface-2 px-3 py-2" data-location-landmark-type>
                <dt className="text-xs text-muted-foreground">{LANDMARK_TYPE_LABEL}</dt>
                <dd className="mt-0.5 font-medium text-foreground wrap-anywhere">
                  <bdi>{location.landmark_type}</bdi>
                </dd>
              </div>
            )}
          </dl>
        ) : (
          <Missing>{NO_LOCATION}</Missing>
        )}
      </dd>
    </div>
  );
}

/**
 * The CURRENT residence (FB-GEO-02). The structured references are the
 * approved geography: current_location is the latest approved actual
 * residence (the displacement place when DISPLACED) and original_location
 * the actual home before displacement. Recorded free text is never shown as
 * if it were that geography: the identity-card residence, the displacement
 * text and the old address text each keep their own, clearly labelled row.
 */
function Residence({ residence }: { residence: FamilyProfile["residence"] }) {
  const legacyParts = residence ? legacyAddressParts(residence.current_address) : [];
  const displaced = residence?.displacement_status === "DISPLACED";

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
        <>
          <SubHeading id="residence-identity-title">السكن حسب بطاقة الهوية</SubHeading>
          <dl className="divide-y divide-stroke-subtle" aria-labelledby="residence-identity-title">
            <Row label="السكن حسب بطاقة الهوية" field="original_residence">
              <Text value={residence.original_residence_text} />
            </Row>
          </dl>

          <SubHeading id="residence-original-title">السكن قبل النزوح</SubHeading>
          <dl className="divide-y divide-stroke-subtle" aria-labelledby="residence-original-title">
            <LocationRow label="الموقع الجغرافي للسكن الفعلي قبل النزوح" field="original_location" location={residence.original_location} />
          </dl>

          <SubHeading id="residence-displacement-title">حالة النزوح</SubHeading>
          <dl className="divide-y divide-stroke-subtle" aria-labelledby="residence-displacement-title">
            <Row label="حالة النزوح" field="displacement_status">
              {residence.displacement_status === null ? <Missing /> : familyDisplacementLabel(residence.displacement_status)}
            </Row>
            {residence.displacement_location_text?.trim() && (
              <Row label={displaced ? DISPLACEMENT_TEXT_LABEL : DISPLACEMENT_TEXT_HISTORY_LABEL} field="displacement_location_text">
                <bdi>{residence.displacement_location_text}</bdi>
              </Row>
            )}
          </dl>

          <SubHeading id="residence-current-title">السكن الحالي</SubHeading>
          <dl className="divide-y divide-stroke-subtle" aria-labelledby="residence-current-title">
            <LocationRow label="الموقع الجغرافي للسكن الحالي" field="current_location" location={residence.current_location} />
            {residence.current_address.address_text?.trim() && (
              <Row label="العنوان التفصيلي" field="address_text">
                <bdi>{residence.current_address.address_text}</bdi>
              </Row>
            )}
            {legacyParts.length > 0 && (
              <Row label="العنوان المسجّل (نص سابق)" field="current_address">
                {legacyParts.map((part, i) => (
                  <span key={i} data-address-part>
                    {i > 0 && "، "}
                    <bdi>{part}</bdi>
                  </span>
                ))}
              </Row>
            )}
            {residence.residence_type?.trim() && (
              <Row label="نوع السكن" field="residence_type">
                <bdi>{residence.residence_type}</bdi>
              </Row>
            )}
            {residence.started_at && (
              <Row label="تاريخ بدء السكن" field="residence_started_at">
                <span className="tabular-nums">{formatDateLong(residence.started_at)}</span>
              </Row>
            )}
          </dl>
        </>
      )}
    </section>
  );
}

/**
 * How many members have registered health data (PWA-3B.6): members with at
 * least one record of any status in the household health response. Shown
 * only when there are some — and silent while loading or on a failure: the
 * line states that data exists, never a health status, and «0» could be
 * read as one.
 */
function HealthLine() {
  const health = useFamilyHouseholdHealthQuery();
  const count = health.data?.members.length ?? 0;
  if (count === 0) return null;

  return (
    <p className="mt-1 text-sm text-muted-foreground" data-household-health>
      {HEALTH_REGISTERED} لـ <span className="tabular-nums">{count}</span> من الأفراد
    </p>
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
      <HealthLine />
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

/**
 * The way to «الاحتياجات والمساعدات» (PWA-3B.7). No counts: the page shows
 * what is registered; a figure here would need its own requests and could
 * be read as a statement about the family's situation.
 */
function SupportEntry() {
  return (
    <section className={card} aria-labelledby="household-support-title" data-household-support>
      <h2 id="household-support-title" className="text-base font-semibold text-foreground">
        الاحتياجات والمساعدات
      </h2>
      <p className="mt-1 text-sm text-muted-foreground">الاحتياجات المسجّلة للأسرة والمساعدات المستلمة.</p>
      <Link
        href="/family/household/support"
        className="mt-3 flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-border px-3.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        data-support-link
      >
        <span className="flex items-center gap-2">
          <HandHeart className="size-4" aria-hidden />
          عرض الاحتياجات والمساعدات
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
            <Declaration declaration={data.declaration} />
            <Residence residence={data.residence} />
            <MembersEntry count={data.family.registered_member_count} />
            <SupportEntry />
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
