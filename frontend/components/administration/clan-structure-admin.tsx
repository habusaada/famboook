"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, Pencil, Plus } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { StructureDialog, type StructureValues } from "@/components/administration/structure-dialog";
import { ApiError } from "@/lib/api/client";
import {
  useClanTree,
  useCreateBranch,
  useCreateBranchGroup,
  useCreateClan,
  useUpdateBranch,
  useUpdateBranchGroup,
  useUpdateClan,
} from "@/lib/api/clans";
import type { Branch, BranchGroup, Clan } from "@/lib/types/api/clan";

const EMPTY: StructureValues = { code: "", name: "", sortOrder: "" };

function sortOrder(values: StructureValues): { sort_order?: number } {
  return values.sortOrder === "" ? {} : { sort_order: Number(values.sortOrder) };
}

function ActiveBadge({ active }: { active: boolean }) {
  return active ? (
    <Badge variant="secondary">مفعّل</Badge>
  ) : (
    <Badge variant="outline" className="text-muted-foreground">
      غير مفعّل
    </Badge>
  );
}

/** Activate/deactivate button; failures surface in the page-level alert. */
function ToggleActive({
  active,
  onToggle,
  pending,
}: {
  active: boolean;
  onToggle: () => void;
  pending: boolean;
}) {
  return (
    <Button variant="ghost" size="sm" onClick={onToggle} disabled={pending}>
      {active ? "إلغاء التفعيل" : "تفعيل"}
    </Button>
  );
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 403) {
    return "لا تملك صلاحية إدارة العشائر والعائلات والفروع.";
  }
  if (error instanceof ApiError && error.status === 422) {
    return error.message422 ?? "تعذّر حفظ التغيير.";
  }
  return "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.";
}

function BranchRow({ branch, onError }: { branch: Branch; onError: (e: unknown) => void }) {
  const update = useUpdateBranch();
  const edit = useUpdateBranch();

  return (
    <li className="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5 text-sm">
      <span className="w-8 text-center tabular-nums text-muted-foreground" dir="ltr">
        {branch.sort_order}
      </span>
      <span className={branch.is_active ? "font-medium" : "text-muted-foreground line-through"}>
        {branch.name}
      </span>
      <span dir="ltr" className="text-xs text-muted-foreground">
        {branch.code}
      </span>
      <ActiveBadge active={branch.is_active} />
      <span className="text-xs text-muted-foreground">{branch.family_count ?? 0} أسرة</span>
      <span className="ms-auto flex items-center gap-1">
        <StructureDialog
          id={`edit-branch-${branch.id}`}
          trigger={
            <Button variant="ghost" size="sm" aria-label={`تعديل ${branch.name}`}>
              <Pencil className="size-4" />
            </Button>
          }
          title="تعديل الفرع"
          description={`الرمز ${branch.code} ثابت ولا يتغيّر.`}
          initial={{ code: branch.code, name: branch.name, sortOrder: String(branch.sort_order) }}
          withCode={false}
          nameRequired
          withSortOrder
          mutation={edit}
          toPayload={(v) => ({ id: branch.id, name: v.name.trim(), ...sortOrder(v) })}
        />
        <ToggleActive
          active={branch.is_active}
          pending={update.isPending}
          onToggle={() => update.mutate({ id: branch.id, is_active: !branch.is_active }, { onError })}
        />
      </span>
    </li>
  );
}

function GroupBlock({ group, onError }: { group: BranchGroup; onError: (e: unknown) => void }) {
  const update = useUpdateBranchGroup();
  const edit = useUpdateBranchGroup();
  const createBranch = useCreateBranch();
  const branches = group.branches ?? [];

  return (
    <div className="rounded-md border p-3">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span className="w-8 text-center tabular-nums text-muted-foreground" dir="ltr">
          {group.sort_order}
        </span>
        <span className="font-semibold">
          {group.name ?? <span className="font-normal text-muted-foreground">مجموعة بدون اسم</span>}
        </span>
        <span dir="ltr" className="text-xs text-muted-foreground">
          {group.code}
        </span>
        <ActiveBadge active={group.is_active} />
        <span className="ms-auto flex items-center gap-1">
          <StructureDialog
            id={`add-branch-${group.id}`}
            trigger={
              <Button variant="outline" size="sm">
                <Plus className="size-4" />
                فرع
              </Button>
            }
            title="إضافة فرع"
            description={`فرع جديد داخل ${group.name ?? "مجموعة بدون اسم"} (${group.code}).`}
            initial={EMPTY}
            withCode
            nameRequired
            withSortOrder
            mutation={createBranch}
            toPayload={(v) => ({ groupId: group.id, code: v.code, name: v.name.trim(), ...sortOrder(v) })}
          />
          <StructureDialog
            id={`edit-group-${group.id}`}
            trigger={
              <Button variant="ghost" size="sm" aria-label={`تعديل المجموعة ${group.code}`}>
                <Pencil className="size-4" />
              </Button>
            }
            title="تعديل مجموعة الفروع"
            description={`الرمز ${group.code} ثابت ولا يتغيّر.`}
            initial={{ code: group.code, name: group.name ?? "", sortOrder: String(group.sort_order) }}
            withCode={false}
            nameRequired={false}
            withSortOrder
            mutation={edit}
            toPayload={(v) => ({ id: group.id, name: v.name.trim() || null, ...sortOrder(v) })}
          />
          <ToggleActive
            active={group.is_active}
            pending={update.isPending}
            onToggle={() => update.mutate({ id: group.id, is_active: !group.is_active }, { onError })}
          />
        </span>
      </div>
      {branches.length === 0 ? (
        <p className="ps-11 pt-2 text-xs text-muted-foreground">لا توجد فروع في هذه المجموعة بعد.</p>
      ) : (
        <ul className="divide-y ps-8">
          {branches.map((branch) => (
            <BranchRow key={branch.id} branch={branch} onError={onError} />
          ))}
        </ul>
      )}
    </div>
  );
}

function ClanCard({ clan, onError }: { clan: Clan; onError: (e: unknown) => void }) {
  const update = useUpdateClan();
  const edit = useUpdateClan();
  const createGroup = useCreateBranchGroup();
  const groups = clan.branch_groups ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2">
          {clan.name}
          <span dir="ltr" className="text-xs font-normal text-muted-foreground">
            {clan.code}
          </span>
          <ActiveBadge active={clan.is_active} />
        </CardTitle>
        <CardDescription>
          {groups.length} مجموعة فروع — {clan.family_count ?? 0} أسرة مسجّلة
        </CardDescription>
        <CardAction className="flex items-center gap-1">
          <StructureDialog
            id={`add-group-${clan.id}`}
            trigger={
              <Button variant="outline" size="sm">
                <Plus className="size-4" />
                مجموعة فروع
              </Button>
            }
            title="إضافة مجموعة فروع"
            description={`مجموعة جديدة داخل ${clan.name}. الاسم اختياري.`}
            initial={EMPTY}
            withCode
            nameRequired={false}
            withSortOrder
            mutation={createGroup}
            toPayload={(v) => ({ clanId: clan.id, code: v.code, name: v.name.trim() || null, ...sortOrder(v) })}
          />
          <StructureDialog
            id={`edit-clan-${clan.id}`}
            trigger={
              <Button variant="ghost" size="sm" aria-label={`تعديل ${clan.name}`}>
                <Pencil className="size-4" />
              </Button>
            }
            title="تعديل العشيرة / العائلة"
            description={`الرمز ${clan.code} ثابت ولا يتغيّر.`}
            initial={{ code: clan.code, name: clan.name, sortOrder: "" }}
            withCode={false}
            nameRequired
            withSortOrder={false}
            mutation={edit}
            toPayload={(v) => ({ id: clan.id, name: v.name.trim() })}
          />
          <ToggleActive
            active={clan.is_active}
            pending={update.isPending}
            onToggle={() => update.mutate({ id: clan.id, is_active: !clan.is_active }, { onError })}
          />
        </CardAction>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {groups.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            لم تُضف مجموعات فروع بعد. يمكن تسجيل الأسر في هذه العشيرة / العائلة بدون فرع.
          </p>
        ) : (
          groups.map((group) => <GroupBlock key={group.id} group={group} onError={onError} />)
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Administration of the Clan → Branch Group → Branch structure
 * (docs/05, permission clan.manage). Nothing is deleted: deactivation
 * only prevents new selection; families keep their Clan/Branch.
 */
export function ClanStructureAdmin() {
  const { data, isLoading, error } = useClanTree();
  const createClan = useCreateClan();
  const [actionError, setActionError] = useState<string | null>(null);
  const onError = (e: unknown) => setActionError(errorMessage(e));
  const clans = data?.data ?? [];

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <Button asChild variant="ghost" className="w-fit gap-1.5 ps-2 text-muted-foreground">
          <Link href="/administration">
            <ArrowRight className="size-4" />
            العودة إلى الإدارة
          </Link>
        </Button>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-xl font-semibold tracking-tight">العشائر والعائلات</h2>
            <p className="text-sm text-muted-foreground">
              العشيرة / العائلة ← مجموعات الفروع ← الفروع ← الأسر. العشيرة / العائلة ليست أسرة.
            </p>
          </div>
          <StructureDialog
            id="add-clan"
            trigger={
              <Button>
                <Plus className="size-4" />
                إضافة عشيرة / عائلة
              </Button>
            }
            title="إضافة عشيرة / عائلة"
            description="عشيرة / عائلة جديدة تُسجَّل تحتها الأسر."
            initial={EMPTY}
            withCode
            nameRequired
            withSortOrder={false}
            mutation={createClan}
            toPayload={(v) => ({ code: v.code, name: v.name.trim() })}
          />
        </div>
      </div>

      {actionError && (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر حفظ التغيير</AlertTitle>
          <AlertDescription className="flex items-center justify-between gap-2">
            {actionError}
            <Button variant="ghost" size="sm" onClick={() => setActionError(null)}>
              إغلاق
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : error ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل البيانات</AlertTitle>
          <AlertDescription>{errorMessage(error)}</AlertDescription>
        </Alert>
      ) : (
        clans.map((clan) => <ClanCard key={clan.id} clan={clan} onError={onError} />)
      )}
    </div>
  );
}
