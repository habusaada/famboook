"use client";

import { useRouter } from "next/navigation";
import {
  ArrowRight,
  CalendarDays,
  Clock,
  MapPin,
  MoreVertical,
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { FamilyOverview } from "@/components/families/family-overview";
import { FamilyMembersTable } from "@/components/families/family-members-table";
import { TabPlaceholder } from "@/components/families/tab-placeholder";
import {
  getFamilyMemberCount,
  getFemaleCount,
  getHouseholdHead,
  getMaleCount,
} from "@/lib/mock-data/families";
import type { Family } from "@/lib/types/family";

const secondaryTabs = [
  { value: "residence", label: "السكن" },
  { value: "assessments", label: "التقييمات" },
  { value: "needs", label: "الاحتياجات" },
  { value: "assistance", label: "المساعدات" },
  { value: "documents", label: "الوثائق" },
  { value: "history", label: "السجل" },
] as const;

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

export function FamilyProfileView({ family }: { family: Family }) {
  const router = useRouter();
  const head = getHouseholdHead(family);

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
              {family.familyCode}
            </h2>
            <FamilyStatusBadge status={family.status} />
          </div>

          <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground">
            <MetaItem icon={User}>{head?.fullName ?? "غير محدد"}</MetaItem>
            <MetaItem icon={MapPin}>
              {family.residence.city}، {family.residence.governorate}
              {family.residence.displacementStatus
                ? ` — ${family.residence.displacementStatus}`
                : ""}
            </MetaItem>
            <MetaItem icon={CalendarDays}>
              تاريخ التسجيل: <span dir="ltr">{family.registrationDate}</span>
            </MetaItem>
            <MetaItem icon={Clock}>آخر تحديث: {family.updatedAt}</MetaItem>
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>عدد أفراد الأسرة</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {getFamilyMemberCount(family)}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>الذكور</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {getMaleCount(family)}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>الإناث</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {getFemaleCount(family)}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>موقع الإقامة الحالي</CardDescription>
            <CardTitle className="text-base font-semibold">
              {family.residence.city}
            </CardTitle>
          </CardHeader>
        </Card>
      </div>

      <Tabs defaultValue="overview">
        <TabsList variant="line" className="w-full justify-start border-b">
          <TabsTrigger value="overview">نظرة عامة</TabsTrigger>
          <TabsTrigger value="members">أفراد الأسرة</TabsTrigger>
          {secondaryTabs.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>

        <TabsContent value="overview" className="mt-4">
          <FamilyOverview family={family} />
        </TabsContent>

        <TabsContent value="members" className="mt-4">
          <FamilyMembersTable family={family} />
        </TabsContent>

        {secondaryTabs.map((tab) => (
          <TabsContent key={tab.value} value={tab.value} className="mt-4">
            <TabPlaceholder />
          </TabsContent>
        ))}
      </Tabs>
    </div>
  );
}
