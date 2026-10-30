"use client";

import { Bell, Info, LayoutDashboard, Lock, Smartphone, UsersRound, type LucideIcon } from "lucide-react";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { DEMO_NOTICE } from "./demo-fixtures";
import { FamilyPortalPanel, HouseholdRegistryPanel, NotificationsPanel, StaffDashboardPanel } from "./demo-panels";

// FB-WEB-02B — the interactive preview of the homepage. Radix Tabs give the tab
// roles and arrow-key navigation (dir: arrows follow the RTL reading order);
// only the active panel is mounted. Rendered in the page, never in an iframe
// (the site sends X-Frame-Options: DENY).

const TABS: { value: string; label: string; icon: LucideIcon; panel: React.ComponentType }[] = [
  { value: "dashboard", label: "لوحة المعلومات", icon: LayoutDashboard, panel: StaffDashboardPanel },
  { value: "registry", label: "السجلات الأسرية", icon: UsersRound, panel: HouseholdRegistryPanel },
  { value: "family", label: "بوابة الأسرة PWA", icon: Smartphone, panel: FamilyPortalPanel },
  { value: "notifications", label: "الإشعارات والإعلانات", icon: Bell, panel: NotificationsPanel },
];

export function PlatformPreview() {
  return (
    <div className="overflow-hidden rounded-2xl border border-stroke-subtle bg-surface-1 shadow-e3" data-platform-preview>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-stroke-subtle bg-surface-2 px-4 py-3 sm:px-5">
        <span className="inline-flex items-center gap-2 rounded-lg border border-stroke-subtle bg-surface-1 px-3 py-1 text-[13px] font-semibold text-muted-foreground">
          <Lock className="size-3.5 text-brand-600" aria-hidden />
          <bdi dir="ltr">famboook.com</bdi>
        </span>
        <span className="inline-flex items-center rounded-full bg-success-soft px-2.5 py-0.5 text-[13px] font-semibold text-success">بيئة توضيحية</span>
      </div>

      <Tabs defaultValue="dashboard" dir="rtl" className="gap-0">
        {/* A grid, not a scrolling strip: all four tabs stay visible at every width. */}
        <TabsList
          aria-label="أقسام معاينة المنصة"
          className="grid h-auto! w-full grid-cols-2 gap-2 rounded-none border-b border-stroke-subtle bg-surface-2/50 p-3 sm:px-5 md:grid-cols-4"
        >
          {TABS.map(({ value, label, icon: Icon }) => (
            <TabsTrigger
              key={value}
              value={value}
              className="h-auto min-h-11 gap-2 rounded-lg border-stroke-subtle bg-surface-1 px-2 py-2 text-[13px] leading-snug font-semibold whitespace-normal text-muted-foreground after:hidden hover:border-brand-600/50 data-active:border-brand-700 data-active:bg-brand-50 data-active:font-bold data-active:text-brand-800 data-active:shadow-e1 sm:text-sm"
            >
              <Icon aria-hidden />
              {label}
            </TabsTrigger>
          ))}
        </TabsList>

        {TABS.map(({ value, panel: Panel }) => (
          <TabsContent key={value} value={value} className="min-h-[460px] min-w-0 bg-canvas p-3 sm:p-6 lg:p-8">
            <p className="mb-4 flex items-start gap-2 rounded-lg bg-surface-2 px-3 py-2 text-[13px] leading-relaxed text-muted-foreground" data-demo-notice>
              <Info className="mt-0.5 size-3.5 shrink-0 text-brand-600" aria-hidden />
              {DEMO_NOTICE}
            </p>
            <Panel />
          </TabsContent>
        ))}
      </Tabs>
    </div>
  );
}
