"use client";

import { AlertCircle, History, Loader2, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useFamilyActivities } from "@/lib/api/activity";
import { ApiError } from "@/lib/api/client";
import type { FamilyActivity } from "@/lib/types/api/activity";
import { familyActivityPresentation, formatActivityTime } from "@/lib/utils/activity";
import { healthRecordTypeLabels } from "@/lib/utils/health";

/** Safe subject line: the person's name and, for health, the broad type only. */
function activitySubject(activity: FamilyActivity): string | null {
  const parts: string[] = [];
  const healthType = activity.metadata.health_record_type;
  if (healthType) parts.push(healthRecordTypeLabels[healthType]);
  if (activity.subject.person) parts.push(activity.subject.person.full_name);
  return parts.length > 0 ? parts.join(" — ") : null;
}

function ActivityRow({ activity }: { activity: FamilyActivity }) {
  const { label, icon: Icon } = familyActivityPresentation[activity.event_type];
  const subject = activitySubject(activity);

  return (
    <li className="flex gap-3 px-4 py-3" data-activity-id={activity.id}>
      <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <Icon className="size-4" />
      </span>
      <div className="flex min-w-0 flex-col gap-0.5">
        <p className="text-sm font-medium">{label}</p>
        {subject && <p className="text-sm text-muted-foreground">{subject}</p>}
        <p className="text-xs text-muted-foreground">
          بواسطة: {activity.actor?.name ?? "النظام"}
          <span className="mx-1.5">·</span>
          <time dateTime={activity.occurred_at}>{formatActivityTime(activity.occurred_at)}</time>
        </p>
      </div>
    </li>
  );
}

export function FamilyActivityTab({ familyCode }: { familyCode: string }) {
  const { data, isLoading, isError, error, hasNextPage, fetchNextPage, isFetchingNextPage } =
    useFamilyActivities(familyCode);

  if (isLoading) {
    return (
      <Card size="sm">
        <CardContent className="flex flex-col gap-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="flex gap-3">
              <Skeleton className="size-8 rounded-full" />
              <div className="flex flex-1 flex-col gap-2">
                <Skeleton className="h-4 w-48" />
                <Skeleton className="h-3 w-32" />
              </div>
            </div>
          ))}
        </CardContent>
      </Card>
    );
  }

  if (isError) {
    if (error instanceof ApiError && error.status === 403) {
      return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
          <Lock className="size-8 text-muted-foreground" />
          <p className="text-sm text-muted-foreground">
            لا تملك صلاحية عرض سجل نشاط هذه الأسرة.
          </p>
        </div>
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
    <Card size="sm">
      <CardHeader>
        <CardTitle>سجل النشاط</CardTitle>
        <CardDescription>
          العمليات المسجّلة تلقائيًا على الأسرة وأفرادها، الأحدث أولًا
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        {activities.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 p-12 text-center">
            <History className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">
              لا توجد أنشطة مسجّلة لهذه الأسرة بعد.
            </p>
            <p className="text-xs text-muted-foreground">
              يبدأ السجل من العمليات التي تتم بعد تفعيل هذه الميزة.
            </p>
          </div>
        ) : (
          <>
            <ol className="divide-y">
              {activities.map((activity) => (
                <ActivityRow key={activity.id} activity={activity} />
              ))}
            </ol>
            {hasNextPage && (
              <div className="flex justify-center border-t p-3">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={isFetchingNextPage}
                  onClick={() => fetchNextPage()}
                >
                  {isFetchingNextPage && <Loader2 className="size-4 animate-spin" />}
                  عرض المزيد
                </Button>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
