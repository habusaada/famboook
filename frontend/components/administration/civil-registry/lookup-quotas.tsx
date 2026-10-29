"use client";

import { useState } from "react";
import Link from "next/link";
import { Infinity as InfinityIcon, KeyRound, Save } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { DetailItem, DetailList, PageHeader, Panel, SectionHeader } from "@/components/shared/page-layout";
import { EmptyState } from "@/components/shared/empty-state";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { CivilRegistryNav, LoadingBlock, MonitorError, Num } from "@/components/administration/civil-registry/parts";
import { gazaTime, roleLabel } from "@/components/civil-lookup/labels";
import { ApiError } from "@/lib/api/client";
import { useCivilLookupChanges, useCivilLookupUsage, useUpdateCivilLookupPolicy } from "@/lib/api/civil-lookup";
import type { CivilLookupPolicy, CivilLookupPolicyChange } from "@/lib/types/api/civil-lookup";

// FB-CIVIL-02A — Administration → Civil Registry → Lookup quotas
// (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-3, §L-6). Usage needs
// civil-lookup.usage.view; changing a policy or a user's access needs
// civil-lookup.manage. Buttons follow the permissions as UX only — the API
// authorizes and records every change. FB-CIVIL-02D: individual user access
// (overrides, per-side grants and their history) has its own page,
// /administration/civil-registry/lookup-access; this page keeps the roles.

function saveError(error: unknown): string | null {
  if (!error) return null;
  if (error instanceof ApiError && error.status === 422) {
    return Object.values(error.validationErrors ?? {})[0]?.[0] ?? "قيمة غير صالحة.";
  }
  if (error instanceof ApiError && error.status === 403) return "لا تملك صلاحية تعديل الكوتة.";
  return "تعذّر حفظ التغيير.";
}

function PolicyRow({ policy, manage }: { policy: CivilLookupPolicy; manage: boolean }) {
  const update = useUpdateCivilLookupPolicy();
  const [enabled, setEnabled] = useState(policy.enabled);
  const [limit, setLimit] = useState(policy.daily_limit === null ? "" : String(policy.daily_limit));
  const dirty = enabled !== policy.enabled || (!policy.unlimited_allowed && limit !== String(policy.daily_limit ?? ""));
  const error = saveError(update.error);

  return (
    <TableRow data-policy={policy.role}>
      <TableCell className="font-medium">{roleLabel(policy.role)}</TableCell>
      <TableCell>
        {policy.unlimited_allowed ? (
          // FB-CIVIL-02C (corrected): SUPER_ADMIN and ADMINISTRATOR — automatic, always enabled (the API refuses otherwise).
          <span className="inline-flex" data-automatic>
            <StatusBadge tone="success">تلقائي — مفعّلة دائمًا</StatusBadge>
          </span>
        ) : manage ? (
          <label className="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} aria-label={`تفعيل الخدمة لدور ${roleLabel(policy.role)}`} />
            {enabled ? "مفعّلة" : "معطّلة"}
          </label>
        ) : (
          <StatusBadge tone={policy.enabled ? "success" : "neutral"}>{policy.enabled ? "مفعّلة" : "معطّلة"}</StatusBadge>
        )}
      </TableCell>
      <TableCell>
        {policy.unlimited_allowed ? (
          <span className="inline-flex items-center gap-1 text-sm" data-unlimited>
            <InfinityIcon className="size-4" aria-hidden />
            غير محدود
          </span>
        ) : manage ? (
          <Input
            type="number"
            min={1}
            max={100000}
            dir="ltr"
            className="h-8 w-28"
            value={limit}
            onChange={(e) => setLimit(e.target.value)}
            aria-label={`الحد اليومي لدور ${roleLabel(policy.role)}`}
          />
        ) : (
          <Num value={policy.daily_limit} />
        )}
      </TableCell>
      <TableCell>
        <Num value={policy.today.lookups} />
      </TableCell>
      <TableCell>
        <Num value={policy.today.users} />
      </TableCell>
      <TableCell>
        <Num value={policy.today.exhausted} />
      </TableCell>
      {manage && (
        <TableCell className="text-end">
          <div className="flex flex-col items-end gap-1">
            <Button
              size="sm"
              variant="outline"
              disabled={!dirty || update.isPending}
              onClick={() =>
                update.mutate({ role: policy.role, enabled, daily_limit: policy.unlimited_allowed ? null : Number.parseInt(limit, 10) || 0 })
              }
            >
              <Save className="size-3.5" aria-hidden />
              حفظ
            </Button>
            {error && (
              <span className="text-xs text-danger" role="alert">
                {error}
              </span>
            )}
          </div>
        </TableCell>
      )}
    </TableRow>
  );
}

function changeText(c: CivilLookupPolicyChange): string {
  const state = (enabled: boolean | null, limit: number | null) =>
    `${enabled ? "مفعّلة" : "معطّلة"}، ${limit === null ? "غير محدود" : `${limit.toLocaleString("ar")} يوميًا`}`;
  return `${roleLabel(c.role ?? "")}: ${state(c.old_enabled, c.old_daily_limit)} ← ${state(c.new_enabled, c.new_daily_limit)}`;
}

export function CivilLookupQuotas() {
  const { can } = useAuth();
  const manage = can("civil-lookup.manage");
  const usage = useCivilLookupUsage();
  const [changesPage, setChangesPage] = useState(1);
  // Role-policy changes only; per-user changes are on the access page.
  const changes = useCivilLookupChanges(changesPage, manage, { subject: "ROLE" });

  return (
    <div className="flex flex-col gap-5" data-lookup-quotas>
      <PageHeader title="كوتة الاستعلامات" description="الحد اليومي لاستعلامات السجل المدني لكل دور، واستهلاك اليوم، وسجل التغييرات." />
      <CivilRegistryNav />

      {usage.isPending && <LoadingBlock rows={5} />}
      {usage.isError && <MonitorError error={usage.error} onRetry={() => usage.refetch()} />}
      {usage.data && (
        <>
          <Panel>
            <DetailList>
              <DetailItem label="حالة الخدمة">
                <span data-lookup-state={usage.data.lookup_enabled ? "ENABLED" : "DISABLED"}>
                  <StatusBadge tone={usage.data.lookup_enabled ? "success" : "neutral"}>
                    {usage.data.lookup_enabled ? "مفعّلة" : "غير مفعّلة على هذا الخادم"}
                  </StatusBadge>
                </span>
              </DetailItem>
              <DetailItem label="الاستعلام في بوابة الأسرة">
                <span data-side-state="FAMILY">{usage.data.side_lookup_enabled?.FAMILY ? "مفعّل" : "غير مفعّل"}</span>
              </DetailItem>
              <DetailItem label="الاستعلام في مساحة التنسيق">
                <span data-side-state="COORDINATOR">{usage.data.side_lookup_enabled?.COORDINATOR ? "مفعّل" : "غير مفعّل"}</span>
              </DetailItem>
              <DetailItem label="البحث في العلاقات العكسية (الأسرة، الأبناء)">
                {usage.data.reverse_links_enabled ? "مفعّل" : "غير مفعّل"}
              </DetailItem>
              <DetailItem label="استعلامات اليوم">
                <Num value={usage.data.total_lookups} />
              </DetailItem>
              <DetailItem label="مستخدمون استعلموا اليوم">
                <Num value={usage.data.active_users} />
              </DetailItem>
              <DetailItem label="إعادة التصفير">{gazaTime(usage.data.resets_at)} (بتوقيت غزة)</DetailItem>
            </DetailList>
          </Panel>

          <Panel flush>
            <div className="px-4 pt-4 sm:px-5">
              <SectionHeader
                title="الأدوار"
                description="يحتاج المنسق ومستخدم بوابة الأسرة أيضًا إلى تفعيل جهتهما ومنح فردي صريح، ولا يمنح تعيين المنسق صلاحية الاستعلام تلقائيًا."
              />
            </div>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>الدور</TableHead>
                  <TableHead>الخدمة</TableHead>
                  <TableHead>الحد اليومي</TableHead>
                  <TableHead>استعلامات اليوم</TableHead>
                  <TableHead>المستخدمون</TableHead>
                  <TableHead>استنفدوا الرصيد</TableHead>
                  {manage && (
                    <TableHead>
                      <span className="sr-only">حفظ</span>
                    </TableHead>
                  )}
                </TableRow>
              </TableHeader>
              <TableBody>
                {usage.data.policies.map((p) => (
                  <PolicyRow key={`${p.role}-${p.enabled}-${p.daily_limit}`} policy={p} manage={manage} />
                ))}
              </TableBody>
            </Table>
          </Panel>

          <Panel>
            <SectionHeader title="مستخدمون استنفدوا رصيد اليوم" className="mb-2" />
            {usage.data.exhausted_users.length === 0 ? (
              <p className="text-[13px] text-muted-foreground">لا يوجد.</p>
            ) : (
              <ul className="flex flex-col divide-y divide-border/70 text-sm" data-exhausted-users>
                {usage.data.exhausted_users.map((u) => (
                  <li key={u.user_id} className="flex justify-between gap-2 py-2">
                    <span>
                      {u.name} — {roleLabel(u.role)}
                    </span>
                    <span>
                      <Num value={u.used} /> / <Num value={u.daily_limit} />
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </>
      )}

      {manage && (
        <Panel data-access-link>
          <SectionHeader
            title="تصاريح المستخدمين الأفراد"
            description="الإيقاف الفردي والمنح الصريح لكل جهة وسجلهما في صفحة مستقلة."
            icon={KeyRound}
            action={
              <Button asChild size="sm" variant="outline">
                <Link href="/administration/civil-registry/lookup-access">تصاريح الاستعلام المدني</Link>
              </Button>
            }
          />
        </Panel>
      )}

      {manage && (
        <Panel flush data-policy-changes>
          <div className="px-4 pt-4 sm:px-5">
            <SectionHeader title="سجل تغييرات سياسات الأدوار" description="القيمة السابقة والجديدة، ومن غيّرها ومتى. السجل غير قابل للتعديل أو الحذف." />
          </div>
          {changes.isPending && (
            <div className="p-4">
              <LoadingBlock rows={3} />
            </div>
          )}
          {changes.data && changes.data.data.length === 0 && <EmptyState icon={Save} title="لا توجد تغييرات بعد." />}
          {changes.data && changes.data.data.length > 0 && (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>التغيير</TableHead>
                    <TableHead>بواسطة</TableHead>
                    <TableHead>الوقت (بتوقيت غزة)</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {changes.data.data.map((c) => (
                    <TableRow key={c.id} data-change={c.subject_type}>
                      <TableCell>{changeText(c)}</TableCell>
                      <TableCell>{c.changed_by ?? "—"}</TableCell>
                      <TableCell>{gazaTime(c.changed_at)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <RegistryPagination
                meta={{
                  ...changes.data.meta,
                  from: (changes.data.meta.current_page - 1) * changes.data.meta.per_page + 1,
                  to: (changes.data.meta.current_page - 1) * changes.data.meta.per_page + changes.data.data.length,
                  path: "",
                }}
                onPage={setChangesPage}
                unit="تغيير"
              />
            </>
          )}
        </Panel>
      )}
    </div>
  );
}
