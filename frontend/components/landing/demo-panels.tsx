"use client";

import { Bell, CircleCheck, FileText, House, Megaphone, Plus, Send, ShieldCheck, UserRound, Users, UsersRound, type LucideIcon } from "lucide-react";
import { AnnouncementRowContent } from "@/components/announcements/announcement-row";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { FamilyBrand } from "@/components/family/family-brand";
import { FamilyIdentityCard, HouseholdSummaryCard } from "@/components/family/home/household-cards";
import { WelcomeHeader } from "@/components/family/home/welcome-header";
import { MemberCard } from "@/components/family/members/member-card";
import { NOTIFICATION_ROW_CLASS, NotificationRowContent } from "@/components/family/notifications/notification-parts";
import { ActivityItem } from "@/components/shared/activity-item";
import { AppCard } from "@/components/shared/app-card";
import { Initials } from "@/components/shared/initials";
import { KpiCard } from "@/components/shared/kpi-card";
import { LegendItem, SegmentedBar } from "@/components/shared/meter";
import { Code, SectionHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatStrip } from "@/components/shared/stat-strip";
import { StatusBadge } from "@/components/shared/status-badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { relationshipLabel } from "@/lib/utils/relationship";
import {
  demoAnnouncements,
  demoDashboard,
  demoFamilyUser,
  demoHousehold,
  demoMembers,
  demoNotifications,
  demoRegistryFamily,
  demoRegistryMembers,
} from "./demo-fixtures";

// FB-WEB-02B — the four panels of the public preview: the application's real
// presentational components, fed only by demo-fixtures.ts. Nothing here reads
// a session, calls the API or navigates: links are switched off at the
// component boundary (no `href`, showMembersLink / showMyDataLink = false).

const fmt = (n: number) => n.toLocaleString("ar");

export function StaffDashboardPanel() {
  const { kpis, requests, branches, pending } = demoDashboard;
  const total = branches.reduce((sum, b) => sum + b.value, 0);

  return (
    <div className="flex flex-col gap-5" data-demo-panel="dashboard">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4">
        <KpiCard icon={Users} label="الأسر المسجلة" value={fmt(kpis.families)} context="موزعة عبر الفروع" />
        <KpiCard icon={UsersRound} tone="info" label="إجمالي الأفراد المقيدين" value={fmt(kpis.members)} context="ضمن سجلات الأسر" />
        <KpiCard icon={FileText} tone="warning" label="طلبات التحديث الواردة" value={fmt(kpis.requests)} context="تُراجع قبل تطبيقها" />
        <KpiCard icon={Megaphone} tone="success" label="الإعلانات النشطة" value={fmt(kpis.announcements)} context="داخل بوابة الأسرة" />
      </div>

      <StatStrip size="md" items={requests.map((r) => ({ label: r.label, value: fmt(r.value), hint: r.hint }))} />

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <AppCard className="lg:col-span-2">
          <SectionHeader icon={Users} title="الأسر حسب الفروع" description="مثال على عرض الهيكل التنظيمي" className="mb-4" />
          <SegmentedBar size="lg" label="الأسر حسب الفروع" segments={branches.map((b) => ({ ...b }))} />
          <div className="mt-4 flex flex-col gap-2">
            {branches.map((b) => (
              <LegendItem key={b.key} label={b.label} value={b.value} total={total} tone={b.tone} />
            ))}
          </div>
        </AppCard>

        <AppCard>
          <SectionHeader icon={FileText} title="طلبات بانتظار المراجعة" className="mb-4" />
          <ul>
            {pending.map((request, index) => (
              <ActivityItem
                key={request.id}
                icon={FileText}
                title={request.title}
                entity={<Code>{request.entity}</Code>}
                meta={request.meta}
                last={index === pending.length - 1}
              />
            ))}
          </ul>
          <p className="flex items-center gap-1.5 border-t border-stroke-subtle pt-3 text-[13px] text-muted-foreground">
            <ShieldCheck className="size-4 shrink-0 text-success" aria-hidden />
            الوصول مقيد بالصلاحيات المحددة لكل دور
          </p>
        </AppCard>
      </div>
    </div>
  );
}

function RegistryMemberIdentity({ member }: { member: (typeof demoRegistryMembers)[number] }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Initials name={member.full_name} className={member.is_household_head ? "size-9 bg-brand-700 text-white" : "size-9 bg-surface-2 text-muted-foreground"} />
      <div className="flex min-w-0 flex-col">
        <span className="truncate font-medium text-foreground">{member.full_name}</span>
        <Code className="text-xs font-normal text-subtle-foreground">{member.person_code}</Code>
      </div>
    </div>
  );
}

export function HouseholdRegistryPanel() {
  const count = demoRegistryMembers.length;

  return (
    <div className="flex flex-col gap-4" data-demo-panel="registry">
      <AppCard className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <h3 className="text-sm font-bold text-foreground">سجل أسري توضيحي: أسرة افتراضية</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            رمز الأسرة <Code>{demoRegistryFamily.code}</Code> · {demoRegistryFamily.branch}
          </p>
        </div>
        <FamilyStatusBadge status={demoRegistryFamily.status} />
      </AppCard>

      <AppCard padded={false} className="overflow-hidden">
        {/* Narrow screens rearrange each member into a row instead of clipping the table. */}
        <ul className="divide-y divide-stroke-subtle md:hidden" data-demo-registry-rows>
          {demoRegistryMembers.map((m) => (
            <li key={m.person_code} className="flex flex-col gap-2.5 px-4 py-3.5">
              <RegistryMemberIdentity member={m} />
              <div className="flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground">
                {m.is_household_head ? <StatusBadge tone="brand">{relationshipLabel(m.relationship, m.gender)}</StatusBadge> : <span>{relationshipLabel(m.relationship, m.gender)}</span>}
                <StatusBadge tone="success">{m.is_household_head ? "مفعّل في البوابة" : "مسجّل في السجل"}</StatusBadge>
              </div>
              <p className="text-[13px] text-muted-foreground">صلاحية التحديث: {m.access}</p>
            </li>
          ))}
        </ul>
        <div className="hidden md:block">
          <Table className="min-w-[560px]">
            <TableHeader className="bg-surface-2">
              <TableRow className="hover:bg-transparent">
                <TableHead className="h-10 ps-5 text-xs font-medium text-muted-foreground">الفرد (اسم رمزي)</TableHead>
                <TableHead className="h-10 text-xs font-medium text-muted-foreground">صلة القرابة</TableHead>
                <TableHead className="h-10 text-xs font-medium text-muted-foreground">الحالة في السجل</TableHead>
                <TableHead className="h-10 pe-5 text-xs font-medium text-muted-foreground">صلاحية التحديث</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {demoRegistryMembers.map((m) => (
                <TableRow key={m.person_code} className="h-16 border-stroke-subtle">
                  <TableCell className="ps-5">
                    <RegistryMemberIdentity member={m} />
                  </TableCell>
                  <TableCell>
                    {m.is_household_head ? <StatusBadge tone="brand">{relationshipLabel(m.relationship, m.gender)}</StatusBadge> : relationshipLabel(m.relationship, m.gender)}
                  </TableCell>
                  <TableCell>
                    <StatusBadge tone="success">{m.is_household_head ? "مفعّل في البوابة" : "مسجّل في السجل"}</StatusBadge>
                  </TableCell>
                  <TableCell className="pe-5 text-muted-foreground">{m.access}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <div className="border-t border-stroke-subtle">
          <RegistryPagination unit="فرد" onPage={() => undefined} meta={{ current_page: 1, last_page: 1, per_page: 25, total: count, from: 1, to: count, path: "" }} />
        </div>
      </AppCard>
    </div>
  );
}

const DEMO_NAV: { label: string; icon: LucideIcon; current?: boolean }[] = [
  { label: "الرئيسية", icon: House, current: true },
  { label: "أسرتي", icon: UsersRound },
  { label: "طلباتي", icon: FileText },
  { label: "حسابي", icon: UserRound },
];

/** The look of the Family Portal's bottom navigation, without links: this is a picture of it, not navigation. */
function DemoBottomNav() {
  return (
    <div className="sticky bottom-0 z-10 border-t border-border bg-surface-1" aria-hidden data-demo-nav>
      <div className="flex items-stretch px-2">
        {DEMO_NAV.slice(0, 2).map((entry) => (
          <DemoNavEntry key={entry.label} {...entry} />
        ))}
        <div className="flex flex-1 items-center justify-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-brand-700 text-white">
            <Plus className="size-6" />
          </span>
        </div>
        {DEMO_NAV.slice(2).map((entry) => (
          <DemoNavEntry key={entry.label} {...entry} />
        ))}
      </div>
    </div>
  );
}

function DemoNavEntry({ label, icon: Icon, current }: { label: string; icon: LucideIcon; current?: boolean }) {
  return (
    <div className={`flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-xs ${current ? "font-semibold text-brand-700" : "font-medium text-muted-foreground"}`}>
      <Icon className="size-5" />
      {label}
    </div>
  );
}

export function FamilyPortalPanel() {
  return (
    <div className="flex justify-center" data-demo-panel="family">
      {/* data-portal: the Family Portal's own tokens; no Family layout, gate or service worker. */}
      <div
        data-portal="family"
        className="w-full max-w-[380px] overflow-hidden rounded-[2rem] border-4 border-foreground bg-canvas shadow-e2"
      >
        {/* Focusable so that keyboard users can scroll the picture of the phone. */}
        <div
          role="group"
          tabIndex={0}
          aria-label="معاينة بوابة الأسرة ببيانات توضيحية، يمكن تمريرها"
          className="max-h-[min(640px,78svh)] overflow-y-auto lg:max-h-[min(560px,58svh)] [scrollbar-width:thin] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
        >
          <div className="sticky top-0 z-10 flex h-14 items-center justify-between gap-3 border-b border-border bg-surface-1 px-4">
            <FamilyBrand />
            <Bell className="size-5 text-muted-foreground" aria-hidden />
          </div>
          <div className="flex flex-col gap-4 px-4 pt-6 pb-6">
            <WelcomeHeader user={demoFamilyUser} />
            <FamilyIdentityCard household={demoHousehold} />
            <HouseholdSummaryCard household={demoHousehold} showMembersLink={false} />
            <div className={NOTIFICATION_ROW_CLASS}>
              <NotificationRowContent notification={demoNotifications[1]} />
            </div>
            <ul className="flex flex-col gap-3">
              {demoMembers.map((member) => (
                <MemberCard key={member.member_ref} member={member} showMyDataLink={false} />
              ))}
            </ul>
          </div>
          <DemoBottomNav />
        </div>
      </div>
    </div>
  );
}

export function NotificationsPanel() {
  return (
    <div className="grid grid-cols-1 gap-5 md:grid-cols-2" data-demo-panel="notifications">
      <AppCard className="flex flex-col gap-4">
        <SectionHeader icon={Send} title="واجهة الإعلانات للجهات المخولة" description="إعلانات موجهة تظهر داخل بوابة الأسرة للمستهدفين." />
        <ul className="flex flex-col divide-y divide-stroke-subtle rounded-lg border border-stroke-subtle">
          {demoAnnouncements.map((announcement) => (
            <li key={announcement.id} className="flex items-center gap-3 px-4 py-3.5">
              <AnnouncementRowContent announcement={announcement} />
            </li>
          ))}
        </ul>
        <p className="border-t border-stroke-subtle pt-3 text-[13px] text-muted-foreground">تُنشر الإعلانات يدويًا داخل المنصة وتظهر للمستخدمين المستهدفين في حساباتهم.</p>
      </AppCard>

      <AppCard className="flex flex-col gap-4" data-portal="family">
        <SectionHeader icon={Bell} tone="success" title="الإشعار داخل حساب رب الأسرة" description="ما يراه رب الأسرة في قائمة الإشعارات." />
        <ul className="flex flex-col gap-3">
          {demoNotifications.map((notification) => (
            <li key={notification.id} className={NOTIFICATION_ROW_CLASS}>
              <NotificationRowContent notification={notification} />
            </li>
          ))}
        </ul>
        <p className="flex items-center gap-1.5 text-[13px] text-muted-foreground">
          <CircleCheck className="size-4 shrink-0 text-success" aria-hidden />
          إشعارات داخل المنصة، دون الاعتماد على رسائل خارجية
        </p>
      </AppCard>
    </div>
  );
}
