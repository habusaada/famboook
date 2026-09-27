"use client";

import { Activity, AlertCircle, Loader2, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { ActivityItem } from "@/components/shared/activity-item";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { useFamilyActivities } from "@/lib/api/activity";
import { ApiError } from "@/lib/api/client";
import type { FamilyActivity } from "@/lib/types/api/activity";
import { familyActivityPresentation, formatActivityTime } from "@/lib/utils/activity";
import { healthRecordTypeLabels } from "@/lib/utils/health";

/**
 * Safe subject line: the Need title (if any), the person's name and, for
 * health, the broad type only.
 */
export function activitySubject(activity: FamilyActivity): string | null {
  const parts: string[] = [];
  if (activity.subject.title) parts.push(activity.subject.title);
  const healthType = activity.metadata.health_record_type;
  if (healthType) parts.push(healthRecordTypeLabels[healthType]);
  if (activity.subject.person) parts.push(activity.subject.person.full_name);
  return parts.length > 0 ? parts.join(" — ") : null;
}

export function FamilyActivityTab({ familyCode }: { familyCode: string }) {
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useFamilyActivities(familyCode);

  if (isLoading) {
    return <Skeleton className="h-48 w-full rounded-widget" />;
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض سجل نشاط هذه الأسرة." />
        </AppCard>
      );
    }

    return (
      <Alert variant="destructive">
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل سجل النشاط</AlertTitle>
        <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
      </Alert>
    );
  }

  const activities = data!.pages.flatMap((page) => page.data);

  return (
    <AppCard>
      <SectionHeader
        icon={Activity}
        tone="neutral"
        title="سجل النشاط"
        description="العمليات المسجّلة تلقائيًا على الأسرة وأفرادها، الأحدث أولًا"
      />
      <div className="mt-4">
        {activities.length === 0 ? (
          <EmptyState
            icon={Activity}
            title="لا توجد أنشطة مسجّلة لهذه الأسرة بعد"
            description="يبدأ السجل من العمليات التي تتم بعد تفعيل هذه الميزة."
          />
        ) : (
          <>
            <ol className="flex flex-col">
              {activities.map((activity, index) => (
                <ActivityItem
                  key={activity.id}
                  data-activity-id={activity.id}
                  icon={familyActivityPresentation[activity.event_type].icon}
                  title={familyActivityPresentation[activity.event_type].label}
                  entity={activitySubject(activity) ?? undefined}
                  meta={
                    <>
                      بواسطة: {activity.actor?.name ?? "النظام"}
                      <span className="mx-1.5">·</span>
                      <time dateTime={activity.occurred_at}>{formatActivityTime(activity.occurred_at)}</time>
                    </>
                  }
                  last={index === activities.length - 1 && !hasNextPage}
                />
              ))}
            </ol>
            {hasNextPage && (
              <div className="flex justify-center border-t border-stroke-subtle pt-3">
                <Button type="button" variant="outline" size="sm" disabled={isFetchingNextPage} onClick={() => fetchNextPage()}>
                  {isFetchingNextPage && <Loader2 className="size-4 animate-spin" />}
                  عرض المزيد
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </AppCard>
  );
}
