"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, ArrowRight, ChevronLeft, Home, IdCard, Phone, SearchX } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { StatusBadge } from "@/components/shared/status-badge";
import { Code, DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { ConfirmAliveDialog } from "@/components/people/confirm-alive-dialog";
import { CorrectNationalIdDialog } from "@/components/people/correct-national-id-dialog";
import { PersonIdentityHeader, genderLabel, lifeStatusLabels } from "@/components/people/person-identity-header";
import { usePerson } from "@/lib/api/people";
import { ApiError } from "@/lib/api/client";
import type { PersonDetail } from "@/lib/types/api/person";
import { UNKNOWN_LABEL, ageLabel, birthDateLabel } from "@/lib/utils/date";
import { maritalStatusLabels } from "@/lib/utils/marital-status";
import { relationshipLabel } from "@/lib/utils/relationship";

/** A value that is not recorded: readable, but quieter than recorded values. */
function Unrecorded({ children = "غير مسجّل" }: { children?: React.ReactNode }) {
  return <span className="font-normal text-muted-foreground">{children}</span>;
}

/** Page context: People › this Person. */
function Breadcrumb({ personCode }: { personCode?: string }) {
  return (
    <nav aria-label="مسار الصفحة" className="flex items-center gap-1 text-[13px] text-muted-foreground">
      <Link href="/people" className="rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
        الأشخاص
      </Link>
      {personCode && (
        <>
          <ChevronLeft className="size-3.5 text-muted-foreground" aria-hidden />
          <Code className="font-medium text-foreground">{personCode}</Code>
        </>
      )}
    </nav>
  );
}

// ------------------------------------------------------------------ sections

/**
 * The current (active) Family membership as returned by the API. The Person
 * Profile does not expose membership history, and membership corrections
 * stay in the Family Profile (Slice C).
 */
function CurrentFamily({ person }: { person: PersonDetail }) {
  const { can } = useAuth();
  const membership = person.family_membership;
  const canViewFamily = can("family.view");

  return (
    <AppCard aria-labelledby="person-family-title">
      <SectionHeader title={<span id="person-family-title">الأسرة الحالية</span>} description="عضوية الفرد النشطة في السجل" />
      {membership ? (
        <div className="mt-3 flex flex-col gap-3 rounded-lg bg-surface-2 p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
          <div className="flex min-w-0 items-center gap-3">
            <IconBox icon={Home} size="md" />
            <div className="flex min-w-0 flex-col gap-1">
              <div className="flex flex-wrap items-center gap-2">
                <Code className="text-[15px] font-semibold text-brand-800">{membership.family_code}</Code>
                {membership.is_household_head ? (
                  <StatusBadge tone="brand">رب الأسرة</StatusBadge>
                ) : (
                  <span className="text-sm font-medium text-foreground">
                    {relationshipLabel(membership.relationship_type, person.gender)}
                  </span>
                )}
              </div>
              <span className="text-[13px] text-muted-foreground">
                {membership.started_at ? (
                  <>
                    عضو منذ <bdi dir="ltr" className="tabular-nums">{membership.started_at}</bdi>
                  </>
                ) : (
                  "تاريخ بدء العضوية غير مسجّل"
                )}
              </span>
            </div>
          </div>
          {canViewFamily && (
            <Button asChild variant="outline" size="sm" className="w-fit shrink-0 gap-1">
              <Link href={`/families/${membership.family_code}`} aria-label={`فتح ملف الأسرة ${membership.family_code}`} data-family-profile-link>
                فتح ملف الأسرة
                <ChevronLeft className="size-4" />
              </Link>
            </Button>
          )}
        </div>
      ) : (
        <EmptyState
          icon={Home}
          title="لا توجد أسرة حالية"
          description="لا توجد عضوية نشطة لهذا الفرد في أي أسرة. تُدار العضوية من ملف الأسرة."
          className="py-6"
        />
      )}
    </AppCard>
  );
}

function PersonalInformation({ person }: { person: PersonDetail }) {
  const { can } = useAuth();
  // UNKNOWN only: there is no action for ALIVE, and never one for DECEASED.
  const canConfirmAlive = person.life_status === "UNKNOWN" && can("person.record-death");

  return (
    <AppCard aria-labelledby="person-info-title">
      <SectionHeader
        title={<span id="person-info-title">البيانات الشخصية</span>}
        description="المعلومات المسجّلة في سجل الفرد"
        action={canConfirmAlive ? <ConfirmAliveDialog person={person} /> : undefined}
      />
      <DetailList className="mt-3">
        <DetailItem label="الاسم الكامل">{person.full_name}</DetailItem>
        <DetailItem label="رقم الفرد">
          <Code>{person.person_code}</Code>
        </DetailItem>
        <DetailItem label="الجنس">{person.gender ? genderLabel(person.gender) : <Unrecorded>غير محدد</Unrecorded>}</DetailItem>
        <DetailItem label="الحالة الاجتماعية">
          {person.marital_status && person.marital_status !== "UNKNOWN" ? (
            maritalStatusLabels[person.marital_status]
          ) : (
            <Unrecorded>{maritalStatusLabels.UNKNOWN}</Unrecorded>
          )}
        </DetailItem>
        <DetailItem label="تاريخ الميلاد">
          {person.birth_date ? <bdi dir="ltr" className="tabular-nums">{birthDateLabel(person.birth_date)}</bdi> : <Unrecorded>{UNKNOWN_LABEL}</Unrecorded>}
        </DetailItem>
        <DetailItem label="العمر">
          {person.birth_date ? <span className="tabular-nums">{ageLabel(person.birth_date)} سنة</span> : <Unrecorded>{UNKNOWN_LABEL}</Unrecorded>}
        </DetailItem>
        <DetailItem label="الحالة الحياتية">
          {person.life_status === "UNKNOWN" ? <Unrecorded>{lifeStatusLabels.UNKNOWN}</Unrecorded> : lifeStatusLabels[person.life_status]}
        </DetailItem>
        <DetailItem label="حالة السجل">{person.is_active ? "نشط" : <Unrecorded>غير نشط</Unrecorded>}</DetailItem>
      </DetailList>
    </AppCard>
  );
}

/** Contact numbers as recorded; no call/message actions exist. */
function ContactInformation({ person }: { person: PersonDetail }) {
  const hasAny = person.mobile || person.alternate_mobile;
  return (
    <AppCard aria-labelledby="person-contact-title">
      <SectionHeader title={<span id="person-contact-title">بيانات التواصل</span>} icon={Phone} tone="neutral" />
      {hasAny ? (
        <DetailList className="mt-3 sm:grid-cols-1">
          <DetailItem label="رقم الجوال">
            {person.mobile ? <bdi dir="ltr" className="tabular-nums">{person.mobile}</bdi> : <Unrecorded />}
          </DetailItem>
          {person.alternate_mobile && (
            <DetailItem label="رقم جوال بديل">
              <bdi dir="ltr" className="tabular-nums">{person.alternate_mobile}</bdi>
            </DetailItem>
          )}
          {person.alternate_mobile && (
            <DetailItem label="صاحب الرقم البديل / صلته">
              {person.alternate_mobile_owner_relation ?? <Unrecorded />}
            </DetailItem>
          )}
        </DetailList>
      ) : (
        <p className="mt-3 text-sm text-muted-foreground" data-no-contact>
          لا يوجد رقم جوال مسجّل
        </p>
      )}
    </AppCard>
  );
}

/**
 * Sensitive identity data (AUTH-ADR-059): rendered only when the API sent
 * `national_id_masked` (person.national-id.view-masked) or the user may run
 * the controlled correction (person.national-id.update). Otherwise nothing —
 * not even a hidden label — reaches the page.
 */
function NationalIdSection({ person }: { person: PersonDetail }) {
  const { can } = useAuth();
  const showsNationalId = person.national_id_masked !== undefined;
  const canCorrect = can("person.national-id.update");
  if (!showsNationalId && !canCorrect) return null;

  return (
    <AppCard aria-labelledby="person-nid-title" data-national-id-card>
      <SectionHeader
        title={<span id="person-nid-title">رقم الهوية</span>}
        description="بيانات حساسة — يظهر الرقم مخفيًا جزئيًا. التصحيح إجراء إداري مضبوط."
        icon={IdCard}
        tone="neutral"
        action={canCorrect ? <CorrectNationalIdDialog person={person} /> : undefined}
      />
      {showsNationalId && (
        <DetailList className="mt-3 sm:grid-cols-1">
          <DetailItem label="رقم الهوية (مخفي جزئيًا)">
            {person.national_id_masked ? (
              <bdi dir="ltr" className="tracking-wider tabular-nums">{person.national_id_masked}</bdi>
            ) : (
              <Unrecorded />
            )}
          </DetailItem>
        </DetailList>
      )}
    </AppCard>
  );
}

// ------------------------------------------------------------------ page

/**
 * Person 360° (existing data only): identity, current family, personal and
 * contact data, and — for authorized users — the masked National ID with
 * its controlled correction. The Person API exposes no health, activity or
 * membership history, so none is shown here.
 */
export function PersonProfileView({ personCode }: { personCode: string }) {
  const router = useRouter();
  const { data, isLoading, isError, error } = usePerson(personCode);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <Skeleton className="h-5 w-32" />
        <Skeleton className="h-44 w-full rounded-widget" />
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
          <Skeleton className="h-64 w-full rounded-widget xl:col-span-7" />
          <Skeleton className="h-64 w-full rounded-widget xl:col-span-5" />
        </div>
      </div>
    );
  }

  if (isError) {
    const notFound = error instanceof ApiError && error.status === 404;

    return (
      <div className="flex flex-col gap-4">
        <Button type="button" variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" onClick={() => router.push("/people")}>
          <ArrowRight className="size-4" />
          الأشخاص
        </Button>
        {notFound ? (
          <AppCard padded={false}>
            <EmptyState
              icon={SearchX}
              title="لم يتم العثور على شخص بهذا الرقم"
              description={
                <>
                  تحقّق من رقم الفرد، أو ابحث عنه في{" "}
                  <Link href="/people" className="font-medium text-brand-700 underline-offset-4 hover:underline">
                    سجل الأشخاص
                  </Link>
                  .
                </>
              }
            />
          </AppCard>
        ) : (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر تحميل بيانات الشخص</AlertTitle>
            <AlertDescription>
              {error instanceof Error ? error.message : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}
            </AlertDescription>
          </Alert>
        )}
      </div>
    );
  }

  const person = data!.data;

  return (
    <div className="flex flex-col gap-4">
      <Breadcrumb personCode={person.person_code} />
      <PersonIdentityHeader person={person} />

      <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
        <div className="flex flex-col gap-4 xl:col-span-7">
          <CurrentFamily person={person} />
          <PersonalInformation person={person} />
        </div>
        <div className="flex flex-col gap-4 xl:col-span-5">
          <ContactInformation person={person} />
          <NationalIdSection person={person} />
        </div>
      </div>
    </div>
  );
}

