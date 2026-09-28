"use client";

import { useState } from "react";
import { useAuth } from "@/components/auth/auth-context";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, ArrowRight, ChevronLeft, SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { FamilyIdentityHeader } from "@/components/families/family-identity-header";
import { useFamilySnapshot } from "@/components/families/family-snapshot";
import { FamilyOverview } from "@/components/families/family-overview";
import { FamilyMembersTable } from "@/components/families/family-members-table";
import { FamilyResidenceTab } from "@/components/families/family-residence-tab";
import { FamilyHealthTab } from "@/components/families/family-health-tab";
import { FamilyActivityTab } from "@/components/families/family-activity-tab";
import { FamilyAssessmentsTab } from "@/components/families/family-assessments-tab";
import { FamilyNeedsTab } from "@/components/families/family-needs-tab";
import { FamilyAssistanceTab } from "@/components/families/family-assistance-tab";
import { AppCard } from "@/components/shared/app-card";
import { Code } from "@/components/shared/page-layout";
import { EmptyState } from "@/components/shared/empty-state";
import { useFamily } from "@/lib/api/families";
import { ApiError } from "@/lib/api/client";

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

// Enterprise tab bar (docs/10): strong selected state (teal text + 2px
// underline + tint), quiet inactive tabs, visible focus, horizontal scroll
// on small screens.
const tabTrigger =
  "h-10 flex-none rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground data-active:bg-surface-selected data-active:font-semibold data-active:text-brand-800 after:bg-brand-700 group-data-horizontal/tabs:after:-bottom-[5px] focus-visible:ring-2 focus-visible:ring-ring/70 focus-visible:ring-inset";

/** Page context: Families › this Family. */
function Breadcrumb({ familyCode }: { familyCode?: string }) {
  return (
    <nav aria-label="مسار الصفحة" className="flex items-center gap-1 text-[13px] text-muted-foreground">
      <Link href="/families" className="rounded-sm px-0.5 hover:text-brand-700 focus-visible:outline-2 focus-visible:outline-ring">
        الأسر
      </Link>
      {familyCode && (
        <>
          <ChevronLeft className="size-3.5 text-subtle-foreground" aria-hidden />
          <Code className="font-medium text-foreground">{familyCode}</Code>
        </>
      )}
    </nav>
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
  const snapshot = useFamilySnapshot(familyCode);
  const [tab, setTab] = useState(
    initialTab && LINKABLE_TABS.includes(initialTab) && showTab(initialTab) ? initialTab : "overview"
  );
  const openTab = (next: string) => {
    if (!showTab(next)) return;
    setTab(next);
    if (typeof window !== "undefined") window.scrollTo({ top: 0, behavior: "smooth" });
  };

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true">
        <Skeleton className="h-5 w-32" />
        <Skeleton className="h-48 w-full rounded-widget" />
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-64 w-full rounded-widget" />
      </div>
    );
  }

  if (isError) {
    const notFound = error instanceof ApiError && error.status === 404;

    return (
      <div className="flex flex-col gap-4">
        <Button type="button" variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" onClick={() => router.push("/families")}>
          <ArrowRight className="size-4" />
          الأسر
        </Button>
        {notFound ? (
          <AppCard padded={false}>
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
          </AppCard>
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
      <Breadcrumb familyCode={family.family_code} />
      <FamilyIdentityHeader family={family} snapshot={snapshot} />

      <Tabs value={tab} onValueChange={setTab} className="gap-0" dir="rtl">
        <TabsList
          variant="line"
          aria-label="أقسام ملف الأسرة"
          className="h-auto! w-full justify-start gap-1 overflow-x-auto overflow-y-hidden rounded-none border-b border-stroke-subtle p-0 pb-1"
        >
          <TabsTrigger className={tabTrigger} value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="members">أفراد الأسرة</TabsTrigger>
          <TabsTrigger className={tabTrigger} value="residence">السكن</TabsTrigger>
          {showTab("health") && <TabsTrigger className={tabTrigger} value="health">الحالة الصحية</TabsTrigger>}
          {showTab("assessments") && <TabsTrigger className={tabTrigger} value="assessments">التقييمات</TabsTrigger>}
          {showTab("needs") && <TabsTrigger className={tabTrigger} value="needs">الاحتياجات</TabsTrigger>}
          {showTab("assistance") && <TabsTrigger className={tabTrigger} value="assistance">المساعدات</TabsTrigger>}
          {showTab("history") && <TabsTrigger className={tabTrigger} value="history">السجل</TabsTrigger>}
        </TabsList>

        <TabsContent value="overview" className="mt-4">
          <FamilyOverview family={family} snapshot={snapshot} onOpenTab={openTab} />
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

        {showTab("history") && (
          <TabsContent value="history" className="mt-4">
            <FamilyActivityTab familyCode={family.family_code} />
          </TabsContent>
        )}
      </Tabs>
    </div>
  );
}
