"use client";

import { useAuth } from "@/components/auth/auth-context";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, ArrowRight, SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { FamilyOverview } from "@/components/families/family-overview";
import { FamilyMembersTable } from "@/components/families/family-members-table";
import { FamilyResidenceTab } from "@/components/families/family-residence-tab";
import { FamilyHealthTab } from "@/components/families/family-health-tab";
import { FamilyActivityTab } from "@/components/families/family-activity-tab";
import { FamilyAssessmentsTab } from "@/components/families/family-assessments-tab";
import { FamilyNeedsTab } from "@/components/families/family-needs-tab";
import { FamilyAssistanceTab } from "@/components/families/family-assistance-tab";
import { TabPlaceholder } from "@/components/families/tab-placeholder";
import { Code, Panel } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { useFamily } from "@/lib/api/families";
import { ApiError } from "@/lib/api/client";
import type { FamilyDetail } from "@/lib/types/api/family";

const secondaryTabs = [
  { value: "documents", label: "الوثائق" },
] as const;

// Tabs that can be opened directly via ?tab= (e.g. returning from an
// assessment screen).
const LINKABLE_TABS = ["overview", "members", "residence", "health", "assessments", "needs", "assistance", "history"];

// Tabs of other modules appear only with that module's view permission
// (docs/06 §59c); the API refuses them anyway.
const TAB_PERMISSIONS: Record<string, string> = {
  health: "health-record.view",
  assessments: "assessment.view",
  needs: "need.view",
  assistance: "assistance.view",
  history: "activity-log.view",
};

const fmt = (n: number) => n.toLocaleString("ar");

// Quiet tab bar: the active tab is obvious (deep teal text and underline),
// inactive tabs stay muted; the bar scrolls horizontally on small screens.
const tabTrigger =
  "h-11 flex-none rounded-none px-3.5 text-sm font-medium text-muted-foreground hover:text-foreground data-active:font-semibold data-active:text-brand-800 after:bg-brand-700 group-data-horizontal/tabs:after:bottom-0 focus-visible:ring-2 focus-visible:ring-ring/70 focus-visible:ring-inset";

function BackToFamilies({ onClick }: { onClick: () => void }) {
  return (
    <Button type="button" variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" onClick={onClick}>
      <ArrowRight className="size-4" />
      الأسر
    </Button>
  );
}

/** Separator between meta facts. */
function Dot() {
  return (
    <span aria-hidden className="text-subtle-foreground">
      •
    </span>
  );
}

function Meta({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex gap-1.5">
      <dt>{label}:</dt>
      <dd className="font-medium text-foreground">{children}</dd>
    </div>
  );
}

const notRecorded = <span className="font-normal text-subtle-foreground">غير مسجّل</span>;

/**
 * Family identity from existing data only: code, status, household head
 * (the data model has no Family name), lineage, current size, paper form
 * number and, for a displaced family, the current displacement location.
 */
function FamilyIdentity({ family }: { family: FamilyDetail }) {
  const head = family.members.find((m) => m.is_household_head);
  const residence = family.residence;
  const displaced = residence?.displacement_status === "DISPLACED";

  return (
    <Panel className="flex flex-col gap-3 p-4 sm:px-6 sm:py-5" aria-label="هوية الأسرة">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <Code className="text-[15px] text-brand-800">{family.family_code}</Code>
            <FamilyStatusBadge status={family.status} />
            {displaced && <StatusBadge tone="warning">نازحة</StatusBadge>}
          </div>
          <div className="flex flex-col gap-0.5">
            <h1 className="text-2xl leading-tight font-bold text-foreground">
              {head ? head.full_name : <span className="text-subtle-foreground">رب الأسرة غير محدد</span>}
            </h1>
            {head && <p className="text-[13px] text-muted-foreground">رب الأسرة</p>}
          </div>
        </div>
      </div>

      <div className="flex flex-col gap-1.5 border-t border-border/70 pt-3 text-sm">
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-foreground">
          <span>{family.branch ? family.branch.name : <span className="text-subtle-foreground">بدون فرع</span>}</span>
          {family.clan && (
            <>
              <Dot />
              <span className="text-muted-foreground">{family.clan.name}</span>
            </>
          )}
          <span className="hidden sm:inline">
            <Dot />
          </span>
          {/* Current members: "5 أفراد · 3 ذكور · 2 إناث" */}
          <span className="flex basis-full flex-wrap items-center gap-x-1.5 sm:basis-auto" data-member-summary>
            <span>
              <span className="font-semibold tabular-nums">{fmt(family.member_count)}</span> أفراد
            </span>
            <span aria-hidden className="text-subtle-foreground">·</span>
            <span className="text-muted-foreground">
              <span className="tabular-nums">{fmt(family.male_count)}</span> ذكور
            </span>
            <span aria-hidden className="text-subtle-foreground">·</span>
            <span className="text-muted-foreground">
              <span className="tabular-nums">{fmt(family.female_count)}</span> إناث
            </span>
          </span>
        </p>
        <dl className="flex flex-wrap gap-x-6 gap-y-1 text-[13px] text-muted-foreground">
          <Meta label="رقم النموذج الورقي">
            {family.paper_form_no ? <bdi dir="ltr">{family.paper_form_no}</bdi> : notRecorded}
          </Meta>
          {family.registration_date && (
            <Meta label="تاريخ التسجيل">
              <bdi dir="ltr">{family.registration_date}</bdi>
            </Meta>
          )}
          {displaced && <Meta label="مكان النزوح الحالي">{residence?.displacement_location_text ?? notRecorded}</Meta>}
          {!displaced && residence && (residence.city || residence.governorate) && (
            <Meta label="السكن الحالي">{[residence.city, residence.governorate].filter(Boolean).join("، ")}</Meta>
          )}
          {family.updated_at && (
            <Meta label="آخر تحديث">
              <span className="font-normal text-muted-foreground">
                {new Date(family.updated_at).toLocaleString("ar", { dateStyle: "medium", timeStyle: "short" })}
              </span>
            </Meta>
          )}
        </dl>
      </div>
    </Panel>
  );
}

export function FamilyProfileView({
  familyCode,
  initialTab,
}: {
  familyCode: string;
  initialTab?: string;
}) {
  const router = useRouter();
  const { can } = useAuth();
  const showTab = (tab: string) => !TAB_PERMISSIONS[tab] || can(TAB_PERMISSIONS[tab]);
  const { data, isLoading, isError, error } = useFamily(familyCode);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-5" aria-busy="true">
        <Skeleton className="h-8 w-20" />
        <Panel className="flex flex-col gap-3 p-6">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-8 w-72 max-w-full" />
          <Skeleton className="h-4 w-96 max-w-full" />
        </Panel>
        <Skeleton className="h-11 w-full" />
      </div>
    );
  }

  if (isError) {
    const notFound = error instanceof ApiError && error.status === 404;

    return (
      <div className="flex flex-col gap-5">
        <BackToFamilies onClick={() => router.push("/families")} />
        {notFound ? (
          <Panel flush>
            <EmptyState
              icon={SearchX}
              title="لم يتم العثور على أسرة بهذا الرقم"
              description={
                <>
                  تحقّق من رقم الأسرة، أو ابحث عنها في{" "}
                  <Link href="/families" className="font-medium text-brand-700 underline-offset-4 hover:underline">
                    سجل الأسر
                  </Link>
                  .
                </>
              }
            />
          </Panel>
        ) : (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر تحميل بيانات الأسرة</AlertTitle>
            <AlertDescription>
              {error instanceof Error ? error.message : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}
            </AlertDescription>
          </Alert>
        )}
      </div>
    );
  }

  const family = data!.data;

  return (
    <div className="flex flex-col gap-4">
      <BackToFamilies onClick={() => router.push("/families")} />
      <FamilyIdentity family={family} />

      <Tabs
        defaultValue={initialTab && LINKABLE_TABS.includes(initialTab) && showTab(initialTab) ? initialTab : "overview"}
        className="gap-0"
        // RTL keyboard semantics: ArrowLeft moves to the next tab visually.
        dir="rtl"
      >
        <TabsList
          variant="line"
          className="h-auto! w-full justify-start gap-0 overflow-x-auto overflow-y-hidden rounded-none border-b p-0"
        >
          <TabsTrigger className={tabTrigger} value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="members">أفراد الأسرة</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="residence">السكن</TabsTrigger>
          {showTab("health") && <TabsTrigger className={tabTrigger} value="health">الحالة الصحية</TabsTrigger>}
          {showTab("assessments") && <TabsTrigger className={tabTrigger} value="assessments">التقييمات</TabsTrigger>}
          {showTab("needs") && <TabsTrigger className={tabTrigger} value="needs">الاحتياجات</TabsTrigger>}
          {showTab("assistance") && <TabsTrigger className={tabTrigger} value="assistance">المساعدات</TabsTrigger>}
          {secondaryTabs.map((tab) => (
            <TabsTrigger className={tabTrigger} key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
          {showTab("history") && <TabsTrigger className={tabTrigger} value="history">السجل</TabsTrigger>}
        </TabsList>

        <TabsContent value="overview" className="mt-4">
          <FamilyOverview family={family} />
        </TabsContent>

        <TabsContent value="members" className="mt-4">
          <FamilyMembersTable family={family} />
        </TabsContent>

        <TabsContent value="residence" className="mt-4">
          <FamilyResidenceTab family={family} />
        </TabsContent>

        {showTab("health") && (
          <TabsContent value="health" className="mt-4">
            <FamilyHealthTab family={family} />
          </TabsContent>
        )}

        {showTab("assessments") && (
          <TabsContent value="assessments" className="mt-4">
            <FamilyAssessmentsTab familyCode={family.family_code} />
          </TabsContent>
        )}

        {showTab("needs") && (
          <TabsContent value="needs" className="mt-4">
            <FamilyNeedsTab familyCode={family.family_code} />
          </TabsContent>
        )}

        {showTab("assistance") && (
          <TabsContent value="assistance" className="mt-4">
            <FamilyAssistanceTab familyCode={family.family_code} />
          </TabsContent>
        )}

        {secondaryTabs.map((tab) => (
          <TabsContent key={tab.value} value={tab.value} className="mt-4">
            <TabPlaceholder />
          </TabsContent>
        ))}

        {showTab("history") && (
          <TabsContent value="history" className="mt-4">
            <FamilyActivityTab familyCode={family.family_code} />
          </TabsContent>
        )}
      </Tabs>
    </div>
  );
}
