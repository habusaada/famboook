"use client";

import { useState } from "react";
import { Infinity as InfinityIcon, Save, UserX } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { DetailItem, DetailList, PageHeader, Panel, SectionHeader } from "@/components/shared/page-layout";
import { EmptyState } from "@/components/shared/empty-state";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { CivilRegistryNav, LoadingBlock, MonitorError, Num } from "@/components/administration/civil-registry/parts";
import { gazaTime, roleLabel } from "@/components/civil-lookup/labels";
import { ApiError } from "@/lib/api/client";
import {
  useCivilLookupChanges,
  useCivilLookupUsage,
  useCivilLookupUserAccess,
  useSetCivilLookupUserAccess,
  useUpdateCivilLookupPolicy,
} from "@/lib/api/civil-lookup";
import type { CivilLookupPolicy, CivilLookupPolicyChange, CivilLookupSide } from "@/lib/types/api/civil-lookup";

// FB-CIVIL-02A — Administration → Civil Registry → Lookup quotas
// (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-3, §L-6). Usage needs
// civil-lookup.usage.view; changing a policy or a user's access needs
// civil-lookup.manage. Buttons follow the permissions as UX only — the API
// authorizes and records every change.

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
        {manage ? (
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

const SIDE_LABELS: Record<CivilLookupSide, string> = { FAMILY: "بوابة الأسرة", COORDINATOR: "مساحة التنسيق" };

const sidesText = (sides: CivilLookupSide[]) => sides.map((side) => SIDE_LABELS[side]).join(" و");

function changeText(c: CivilLookupPolicyChange): string {
  if (c.subject_type === "USER") {
    const label = (a: string | null, sides: CivilLookupSide[]) =>
      a === "REVOKED" ? "موقوف" : a === "GRANTED" ? `ممنوح (${sidesText(sides)})` : "حسب الدور";
    return `${c.user?.name ?? "—"}: ${label(c.old_access, c.old_sides ?? [])} ← ${label(c.new_access, c.new_sides ?? [])}`;
  }
  const state = (enabled: boolean | null, limit: number | null) =>
    `${enabled ? "مفعّلة" : "معطّلة"}، ${limit === null ? "غير محدود" : `${limit.toLocaleString("ar")} يوميًا`}`;
  return `${roleLabel(c.role ?? "")}: ${state(c.old_enabled, c.old_daily_limit)} ← ${state(c.new_enabled, c.new_daily_limit)}`;
}

export function CivilLookupQuotas() {
  const { can } = useAuth();
  const manage = can("civil-lookup.manage");
  const usage = useCivilLookupUsage();
  const [changesPage, setChangesPage] = useState(1);
  const changes = useCivilLookupChanges(changesPage, manage);
  const access = useCivilLookupUserAccess(manage);
  const setAccess = useSetCivilLookupUserAccess();
  const [targetKind, setTargetKind] = useState<"email" | "person_code">("email");
  const [target, setTarget] = useState("");
  const [accessValue, setAccessValue] = useState<"REVOKED" | "DEFAULT" | "GRANTED">("REVOKED");
  const [sides, setSides] = useState<CivilLookupSide[]>([]);
  // FB-CIVIL-02B: GRANTED is offered only for the sides the server says may be granted now.
  const grantable = usage.data?.grantable_sides ?? [];
  const granting = accessValue === "GRANTED";

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
        <Panel data-user-access>
          <SectionHeader
            title="وصول مستخدم محدد"
            description="الإيقاف يمنع المستخدم من الاستعلام أيًا كان دوره، دون تغيير أدواره أو صلاحياته الأخرى. «حسب الدور» يلغي الإيقاف. المنح الفردي لحسابات الأسر فقط، ولكل جهة على حدة، ولا يتجاوز تفعيل الجهة أو سياسة الدور أو الرصيد."
            icon={UserX}
            className="mb-3"
          />
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              const value = target.trim();
              setAccess.mutate(
                {
                  target: targetKind === "email" ? { email: value } : { person_code: value },
                  access: accessValue,
                  sides: granting ? sides : undefined,
                },
                { onSuccess: () => setTarget("") },
              );
            }}
            data-access-form
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="lookup-access-target-kind">المستخدم حسب</Label>
              <select
                id="lookup-access-target-kind"
                className="h-8 rounded-control border border-input bg-background px-2 text-sm"
                value={targetKind}
                onChange={(e) => setTargetKind(e.target.value as "email" | "person_code")}
              >
                <option value="email">البريد الإلكتروني (موظف)</option>
                <option value="person_code">رمز الشخص (حساب أسرة)</option>
              </select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="lookup-access-target">{targetKind === "email" ? "البريد الإلكتروني للمستخدم" : "رمز الشخص المرتبط بالحساب"}</Label>
              <Input
                id="lookup-access-target"
                type={targetKind === "email" ? "email" : "text"}
                dir="ltr"
                className="w-72"
                autoComplete="off"
                value={target}
                onChange={(e) => setTarget(e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="lookup-access-value">الوصول</Label>
              <select
                id="lookup-access-value"
                className="h-8 rounded-control border border-input bg-background px-2 text-sm"
                value={accessValue}
                onChange={(e) => setAccessValue(e.target.value as "REVOKED" | "DEFAULT" | "GRANTED")}
              >
                <option value="REVOKED">إيقاف</option>
                <option value="DEFAULT">حسب الدور</option>
                {grantable.length > 0 && <option value="GRANTED">منح (حساب أسرة)</option>}
              </select>
            </div>
            {granting && (
              <fieldset className="flex flex-col gap-1.5" data-grant-sides>
                <legend className="text-sm font-medium">جهة المنح</legend>
                <div className="flex gap-3">
                  {grantable.map((side) => (
                    <label key={side} className="flex items-center gap-1.5 text-sm">
                      <input
                        type="checkbox"
                        checked={sides.includes(side)}
                        onChange={(e) => setSides((current) => (e.target.checked ? [...current, side] : current.filter((s) => s !== side)))}
                      />
                      {SIDE_LABELS[side]}
                    </label>
                  ))}
                </div>
              </fieldset>
            )}
            <Button type="submit" variant="outline" disabled={target.trim() === "" || (granting && sides.length === 0) || setAccess.isPending}>
              تطبيق
            </Button>
            {saveError(setAccess.error) && (
              <span className="text-sm text-danger" role="alert">
                {saveError(setAccess.error)}
              </span>
            )}
          </form>
          {access.data && access.data.length > 0 && (
            <ul className="mt-4 flex flex-col divide-y divide-border/70 text-sm">
              {access.data.map((row) => (
                <li key={row.user.id ?? row.user.email} className="flex flex-wrap justify-between gap-2 py-2" data-access-row>
                  <span>
                    {row.user.name} <bdi dir="ltr" className="text-muted-foreground">{row.user.email}</bdi>
                  </span>
                  <span className="text-muted-foreground">
                    {row.access === "REVOKED" ? "موقوف" : `ممنوح (${sidesText(row.sides ?? [])})`} — {row.changed_by} — {gazaTime(row.changed_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}

      {manage && (
        <Panel flush data-policy-changes>
          <div className="px-4 pt-4 sm:px-5">
            <SectionHeader title="سجل تغييرات الإعدادات" description="القيمة السابقة والجديدة، ومن غيّرها ومتى. السجل غير قابل للتعديل أو الحذف." />
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
