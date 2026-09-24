"use client";

import { useRouter } from "next/navigation";
import {
  AlertCircle,
  ArrowRight,
  CalendarDays,
  Clock,
  MapPin,
  MoreVertical,
  SearchX,
  User,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
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
import { TabPlaceholder } from "@/components/families/tab-placeholder";
import { useFamily } from "@/lib/api/families";
import { ApiError } from "@/lib/api/client";
import { displacementStatusLabel } from "@/lib/utils/displacement";

const secondaryTabs = [
  { value: "assistance", label: "المساعدات" },
  { value: "documents", label: "الوثائق" },
] as const;

// Tabs that can be opened directly via ?tab= (e.g. returning from an
// assessment screen).
const LINKABLE_TABS = ["overview", "members", "residence", "health", "assessments", "needs", "history"];

function MetaItem({
  icon: Icon,
  children,
}: {
  icon: React.ElementType;
  children: React.ReactNode;
}) {
  return (
    <span className="flex items-center gap-1.5">
      <Icon className="size-3.5 shrink-0" />
      {children}
    </span>
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
  const { data, isLoading, isError, error } = useFamily(familyCode);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-5">
        <Card size="sm">
          <CardContent className="flex flex-col gap-3">
            <Skeleton className="h-8 w-40" />
            <Skeleton className="h-4 w-64" />
          </CardContent>
        </Card>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Card key={i} size="sm">
              <CardHeader className="pb-1">
                <Skeleton className="h-4 w-20" />
                <Skeleton className="mt-2 h-7 w-12" />
              </CardHeader>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  if (isError) {
    const notFound = error instanceof ApiError && error.status === 404;

    return (
      <div className="flex flex-col gap-5">
        <Button
          type="button"
          variant="ghost"
          className="w-fit gap-1.5 ps-2 text-muted-foreground"
          onClick={() => router.push("/families")}
        >
          <ArrowRight className="size-4" />
          سجل العائلات
        </Button>

        {notFound ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
            <SearchX className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">
              لم يتم العثور على أسرة بهذا الرقم
            </p>
          </div>
        ) : (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertTitle>تعذّر تحميل بيانات الأسرة</AlertTitle>
            <AlertDescription>
              {error instanceof Error
                ? error.message
                : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}
            </AlertDescription>
          </Alert>
        )}
      </div>
    );
  }

  const family = data!.data;

  return (
    <div className="flex flex-col gap-5">
      <Card size="sm">
        <CardContent className="flex flex-col gap-3">
          <div className="flex items-center justify-between gap-2">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="-ms-2 w-fit gap-1.5 text-muted-foreground"
              onClick={() => router.push("/families")}
            >
              <ArrowRight className="size-4" />
              سجل العائلات
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              aria-label="إجراءات إضافية"
            >
              <MoreVertical className="size-4" />
            </Button>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <h2 dir="ltr" className="text-end text-xl font-semibold tracking-tight">
              {family.family_code}
            </h2>
            <FamilyStatusBadge status={family.status} />
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground">
            <MetaItem icon={User}>
              {family.members.find((m) => m.is_household_head)?.full_name ??
                "غير محدد"}
            </MetaItem>
            {family.residence && (
              <MetaItem icon={MapPin}>
                {family.residence.city}، {family.residence.governorate}
                {family.residence.displacement_status
                  ? ` — ${displacementStatusLabel(family.residence.displacement_status)}`
                  : ""}
              </MetaItem>
            )}
            {family.registration_date && (
              <MetaItem icon={CalendarDays}>
                تاريخ التسجيل: <span dir="ltr">{family.registration_date}</span>
              </MetaItem>
            )}
            {family.updated_at && (
              <MetaItem icon={Clock}>
                آخر تحديث:{" "}
                <span dir="ltr">
                  {new Date(family.updated_at).toLocaleString("ar", {
                    dateStyle: "medium",
                    timeStyle: "short",
                  })}
                </span>
              </MetaItem>
            )}
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>عدد أفراد الأسرة</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {family.member_count}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>الذكور</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {family.male_count}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>الإناث</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {family.female_count}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>موقع الإقامة الحالي</CardDescription>
            <CardTitle className="text-base font-semibold">
              {family.residence?.city ?? "—"}
            </CardTitle>
          </CardHeader>
        </Card>
      </div>

      <Tabs
        defaultValue={initialTab && LINKABLE_TABS.includes(initialTab) ? initialTab : "overview"}
      >
        <TabsList variant="line" className="w-full justify-start border-b">
          <TabsTrigger value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger value="members">أفراد الأسرة</TabsTrigger>
          <TabsTrigger value="residence">السكن</TabsTrigger>
          <TabsTrigger value="health">الحالة الصحية</TabsTrigger>
          <TabsTrigger value="assessments">التقييمات</TabsTrigger>
          <TabsTrigger value="needs">الاحتياجات</TabsTrigger>
          {secondaryTabs.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
          <TabsTrigger value="history">السجل</TabsTrigger>
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

        <TabsContent value="health" className="mt-4">
          <FamilyHealthTab family={family} />
        </TabsContent>

        <TabsContent value="assessments" className="mt-4">
          <FamilyAssessmentsTab familyCode={family.family_code} />
        </TabsContent>

        <TabsContent value="needs" className="mt-4">
          <FamilyNeedsTab familyCode={family.family_code} />
        </TabsContent>

        {secondaryTabs.map((tab) => (
          <TabsContent key={tab.value} value={tab.value} className="mt-4">
            <TabPlaceholder />
          </TabsContent>
        ))}

        <TabsContent value="history" className="mt-4">
          <FamilyActivityTab familyCode={family.family_code} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
