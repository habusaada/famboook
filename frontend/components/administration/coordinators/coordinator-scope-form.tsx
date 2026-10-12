"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Loader2, Plus } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useClans } from "@/lib/api/clans";
import { coordinatorErrorMessage, refreshCoordinatorAfterRefusal, useAssignCoordinatorScope } from "@/lib/api/coordinator-admin";
import type { AssignCoordinatorScopePayload, CoordinatorAssignment, CoordinatorScopeType } from "@/lib/types/api/coordinator-admin";
import type { Branch, Clan } from "@/lib/types/api/clan";
import { coordinatorScopeTypeLabels } from "@/lib/utils/coordinator-admin";

const LEVELS: readonly CoordinatorScopeType[] = ["BRANCH", "BRANCH_GROUP", "CLAN"];
/** Branch filter values besides a group code. */
const ALL_BRANCHES = "__all__";
const UNGROUPED = "__ungrouped__";

/** Every active Branch of a Clan, grouped first, then the ungrouped ones. */
function branchesOf(clan: Clan | undefined): Branch[] {
  if (!clan) return [];
  return [...(clan.branch_groups ?? []).flatMap((group) => (group.branches ?? []).map((b) => ({ ...b, group: { id: group.id, code: group.code, name: group.display_name ?? group.name } }))), ...(clan.ungrouped_branches ?? []).map((b) => ({ ...b, group: null }))];
}

/**
 * Adds ONE scope to a coordinator: Clan → (Branch Group) → Branch. The
 * choices come only from the selected Clan's ACTIVE structure, so a
 * cross-Clan combination cannot be built; the server still validates every
 * code. A target the coordinator already holds actively is not offered.
 * Several branches are several assignments: add them one after another.
 */
export function CoordinatorScopeForm({ personCode, assignments, onAssigned }: { personCode: string; assignments: CoordinatorAssignment[]; onAssigned: (message: string) => void }) {
  const queryClient = useQueryClient();
  const clans = useClans();
  const assign = useAssignCoordinatorScope(personCode);
  const [level, setLevel] = useState<CoordinatorScopeType>("BRANCH");
  const [clanCode, setClanCode] = useState("");
  const [groupFilter, setGroupFilter] = useState(ALL_BRANCHES);
  const [target, setTarget] = useState("");
  const [error, setError] = useState<string | null>(null);

  const clanList = clans.data?.data ?? [];
  const clan = clanList.find((c) => c.code === clanCode);
  const groups = clan?.branch_groups ?? [];
  const allBranches = branchesOf(clan);
  const branches = allBranches.filter((b) =>
    groupFilter === ALL_BRANCHES ? true : groupFilter === UNGROUPED ? b.group === null : b.group?.code === groupFilter
  );

  // What the coordinator already holds actively (never offered twice).
  const active = assignments.filter((a) => a.active);
  const heldClan = (code: string) => active.some((a) => a.scope_type === "CLAN" && a.clan.code === code);
  const heldGroup = (code: string) => active.some((a) => a.scope_type === "BRANCH_GROUP" && a.clan.code === clanCode && a.branch_group?.code === code);
  const heldBranch = (code: string) => active.some((a) => a.scope_type === "BRANCH" && a.clan.code === clanCode && a.branch?.code === code);

  const ready = clanCode !== "" && (level === "CLAN" ? !heldClan(clanCode) : target !== "");

  function reset(next: Partial<{ level: CoordinatorScopeType; clan: string }>) {
    if (next.level) setLevel(next.level);
    if (next.clan !== undefined) setClanCode(next.clan);
    setGroupFilter(ALL_BRANCHES);
    setTarget("");
    setError(null);
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!ready || assign.isPending) return;
    const payload: AssignCoordinatorScopePayload =
      level === "CLAN"
        ? { scope_type: "CLAN", clan: clanCode }
        : level === "BRANCH_GROUP"
          ? { scope_type: "BRANCH_GROUP", clan: clanCode, branch_group: target }
          : { scope_type: "BRANCH", clan: clanCode, branch: target };
    setError(null);
    assign.mutate(payload, {
      onSuccess: () => {
        setTarget("");
        onAssigned("أُضيف النطاق إلى المنسق.");
      },
      onError: (e) => {
        setError(coordinatorErrorMessage(e));
        refreshCoordinatorAfterRefusal(queryClient, personCode, e);
      },
    });
  }

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4" aria-busy={assign.isPending} data-coordinator-scope-form>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="scope-level">مستوى النطاق</Label>
          <Select value={level} onValueChange={(value) => reset({ level: value as CoordinatorScopeType })}>
            <SelectTrigger id="scope-level" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {LEVELS.map((l) => (
                <SelectItem key={l} value={l}>
                  {coordinatorScopeTypeLabels[l]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-1.5">
          <Label htmlFor="scope-clan">العشيرة</Label>
          <Select value={clanCode === "" ? undefined : clanCode} onValueChange={(value) => reset({ clan: value })} disabled={clans.isPending}>
            <SelectTrigger id="scope-clan" className="w-full">
              <SelectValue placeholder={clans.isPending ? "جارٍ التحميل…" : "اختر العشيرة"} />
            </SelectTrigger>
            <SelectContent>
              {clanList.map((c) => (
                <SelectItem key={c.code} value={c.code} disabled={level === "CLAN" && heldClan(c.code)}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {level === "BRANCH_GROUP" && clan && (
          <div className="flex flex-col gap-1.5 sm:col-span-2">
            <Label htmlFor="scope-group">مجموعة الفروع</Label>
            <Select value={target === "" ? undefined : target} onValueChange={setTarget}>
              <SelectTrigger id="scope-group" className="w-full">
                <SelectValue placeholder={groups.length === 0 ? "لا توجد مجموعات فروع في هذه العشيرة" : "اختر مجموعة الفروع"} />
              </SelectTrigger>
              <SelectContent>
                {groups.map((g) => (
                  <SelectItem key={g.code} value={g.code} disabled={heldGroup(g.code)}>
                    {g.display_name ?? g.name ?? g.code}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {level === "BRANCH" && clan && (
          <>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="scope-branch-group">تصفية حسب المجموعة</Label>
              <Select
                value={groupFilter}
                onValueChange={(value) => {
                  setGroupFilter(value);
                  setTarget("");
                }}
              >
                <SelectTrigger id="scope-branch-group" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL_BRANCHES}>كل الفروع</SelectItem>
                  {groups.map((g) => (
                    <SelectItem key={g.code} value={g.code}>
                      {g.display_name ?? g.name ?? g.code}
                    </SelectItem>
                  ))}
                  {(clan.ungrouped_branches ?? []).length > 0 && <SelectItem value={UNGROUPED}>فروع بلا مجموعة</SelectItem>}
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="scope-branch">الفرع</Label>
              <Select value={target === "" ? undefined : target} onValueChange={setTarget}>
                <SelectTrigger id="scope-branch" className="w-full">
                  <SelectValue placeholder={branches.length === 0 ? "لا توجد فروع" : "اختر الفرع"} />
                </SelectTrigger>
                <SelectContent>
                  {branches.map((b) => (
                    <SelectItem key={b.code} value={b.code} disabled={heldBranch(b.code)}>
                      {b.name}
                      {heldBranch(b.code) ? " (معيّن)" : ""}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </>
        )}
      </div>

      {level === "CLAN" && clanCode !== "" && heldClan(clanCode) && <p className="text-sm text-muted-foreground">هذه العشيرة معيّنة لهذا المنسق مسبقًا.</p>}

      {error && (
        <Alert variant="destructive" data-scope-error>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={!ready || assign.isPending} data-scope-submit>
          {assign.isPending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Plus className="size-4" aria-hidden />}
          إضافة النطاق
        </Button>
        <p className="text-xs text-muted-foreground">لتعيين عدة فروع، أضِفها واحدًا تلو الآخر.</p>
      </div>
    </form>
  );
}
