"use client";

import { useState } from "react";
import { Network, Plus } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { useClans, useCreateClan } from "@/lib/api/clans";
import { MODE_LABELS } from "@/components/administration/import-wizard/labels";
import type { ImportBatchDetail, ImportMode } from "@/lib/types/api/imports";

/** Inline Clan creation (existing POST /clans, clan.manage) — the Clan only. */
function CreateClanForm({ onCreated, onCancel }: { onCreated: (code: string, active: boolean) => void; onCancel?: () => void }) {
  const create = useCreateClan();
  const [name, setName] = useState("");
  const [code, setCode] = useState("");
  const [active, setActive] = useState(true);
  const errors = create.error instanceof ApiError ? create.error.validationErrors ?? {} : {};
  const generic = create.error && !(create.error instanceof ApiError && create.error.status === 422);

  return (
    <form
      className="flex flex-col gap-3 rounded-md border border-stroke-subtle p-4"
      onSubmit={(e) => {
        e.preventDefault();
        create.mutate(
          { code: code.trim(), name: name.trim(), is_active: active },
          { onSuccess: () => onCreated(code.trim(), active) }
        );
      }}
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="new-clan-name">اسم العشيرة</Label>
          <Input id="new-clan-name" value={name} onChange={(e) => setName(e.target.value)} required />
          {errors.name && <p className="text-xs text-danger">{errors.name[0]}</p>}
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="new-clan-code">الكود</Label>
          <Input
            id="new-clan-code"
            dir="ltr"
            className="text-end uppercase"
            placeholder="EXAMPLE_CODE"
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            required
          />
          <p className="text-xs text-muted-foreground">أحرف إنجليزية كبيرة وأرقام و _ فقط. لا يمكن تغييره بعد الحفظ.</p>
          {errors.code && <p className="text-xs text-danger">{errors.code[0]}</p>}
        </div>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} className="size-4 accent-brand-600" />
        نشطة
      </label>
      <p className="text-xs text-muted-foreground">يُنشئ هذا العشيرة فقط — لا تُنشأ مجموعات فروع أو فروع أو أسر.</p>
      {generic && <p className="text-sm text-danger">تعذّر إنشاء العشيرة. الرجاء المحاولة مرة أخرى.</p>}
      <div className="flex gap-2">
        <Button type="submit" disabled={create.isPending || !name.trim() || !code.trim()}>
          {create.isPending ? "جارٍ الإنشاء…" : "إنشاء العشيرة"}
        </Button>
        {onCancel && (
          <Button type="button" variant="ghost" onClick={onCancel}>
            إلغاء
          </Button>
        )}
      </div>
    </form>
  );
}

export function StepClan({
  batch,
  clanCode,
  mode,
  onClanChange,
  onModeChange,
  onNewImport,
}: {
  batch: ImportBatchDetail | null;
  clanCode: string;
  mode: ImportMode | "";
  onClanChange: (code: string) => void;
  onModeChange: (mode: ImportMode) => void;
  onNewImport: () => void;
}) {
  const { can } = useAuth();
  const clans = useClans();
  const [creating, setCreating] = useState(false);
  const [inactiveNotice, setInactiveNotice] = useState(false);
  const list = clans.data?.data ?? [];

  // Once a batch exists its Clan and mode are fixed: a new flow is required.
  if (batch) {
    return (
      <Panel className="flex flex-col gap-4">
        <SectionHeader title="العشيرة ونوع العملية" description="ثابتان لهذه الدفعة. لاستيراد عشيرة أخرى أو بنوع آخر ابدأ عملية جديدة." />
        <dl className="grid gap-3 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-muted-foreground">العشيرة المستهدفة</dt>
            <dd className="font-semibold">{batch.clan?.name}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">نوع العملية</dt>
            <dd className="font-semibold">{MODE_LABELS[batch.import_mode].title}</dd>
          </div>
        </dl>
        <Button variant="outline" className="w-fit" onClick={onNewImport}>
          بدء عملية استيراد جديدة
        </Button>
      </Panel>
    );
  }

  return (
    <Panel className="flex flex-col gap-5">
      <SectionHeader title="العشيرة ونوع العملية" description="حدّد العشيرة المستهدفة صراحة ونوع العملية. لا يُفترض أي منهما تلقائيًا." />

      {clans.isLoading ? (
        <Skeleton className="h-20 w-full" />
      ) : list.length === 0 && !creating ? (
        <EmptyState
          icon={Network}
          title="لا توجد عشائر مسجلة بعد."
          description="أنشئ العشيرة الأولى للبدء في استيراد بيانات الأسر."
          action={
            can("clan.manage") ? (
              <Button onClick={() => setCreating(true)}>
                <Plus className="size-4" />
                إنشاء عشيرة
              </Button>
            ) : (
              <p className="text-sm text-muted-foreground">يلزم مسؤول يملك صلاحية إدارة العشائر لإنشائها.</p>
            )
          }
        />
      ) : (
        <div className="flex flex-col gap-2">
          <Label htmlFor="import-clan">العشيرة المستهدفة</Label>
          <div className="flex flex-wrap items-center gap-2">
            <Select value={clanCode || undefined} onValueChange={onClanChange}>
              <SelectTrigger id="import-clan" className="w-full sm:w-80">
                <SelectValue placeholder="اختر العشيرة" />
              </SelectTrigger>
              <SelectContent>
                {list.map((c) => (
                  <SelectItem key={c.code} value={c.code}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {can("clan.manage") && !creating && (
              <Button variant="outline" onClick={() => setCreating(true)}>
                <Plus className="size-4" />
                إنشاء عشيرة جديدة
              </Button>
            )}
          </div>
        </div>
      )}

      {creating && (
        <CreateClanForm
          onCancel={list.length > 0 ? () => setCreating(false) : undefined}
          onCreated={(code, active) => {
            setCreating(false);
            setInactiveNotice(!active);
            if (active) onClanChange(code);
          }}
        />
      )}
      {inactiveNotice && (
        <Alert>
          <AlertDescription>أُنشئت العشيرة غير نشطة، ولا يمكن استخدامها للاستيراد حتى تُفعَّل من إدارة العشائر.</AlertDescription>
        </Alert>
      )}

      <fieldset className="flex flex-col gap-2" disabled={!clanCode}>
        <legend className="mb-1 text-sm font-medium">نوع العملية</legend>
        {(Object.keys(MODE_LABELS) as ImportMode[]).map((m) => (
          <label
            key={m}
            className="flex cursor-pointer items-start gap-3 rounded-md border border-stroke-subtle p-3 has-checked:border-brand-600 has-checked:bg-brand-50 has-disabled:cursor-not-allowed has-disabled:opacity-60"
          >
            <input type="radio" name="import-mode" value={m} checked={mode === m} onChange={() => onModeChange(m)} className="mt-1 size-4 accent-brand-600" />
            <span className="flex flex-col gap-0.5">
              <span className="font-medium">{MODE_LABELS[m].title}</span>
              <span className="text-xs text-muted-foreground">{MODE_LABELS[m].description}</span>
            </span>
          </label>
        ))}
      </fieldset>
    </Panel>
  );
}
