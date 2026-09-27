"use client";

import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { BranchGroup, Clan } from "@/lib/types/api/clan";

// Radix Select cannot use "" as an item value.
const ALL = "__all__";

export interface DashboardScopeValue {
  clan: string;
  group: string;
  branch: string;
}

/** Existing Clan Structure display: own name, else the branches' names. */
export function groupLabel(group: BranchGroup): string {
  const label = group.name ?? group.display_name ?? group.code;
  return group.is_active ? label : `${label} (غير مفعّلة)`;
}

/**
 * Clan (required) → Branch Group (optional) → Branch (optional). Changing
 * the Clan clears both; changing the group clears a Branch outside it.
 */
export function DashboardScopeFilters({
  clans,
  value,
  onChange,
}: {
  clans: Clan[];
  value: DashboardScopeValue;
  onChange: (next: DashboardScopeValue) => void;
}) {
  const clan = clans.find((c) => c.code === value.clan);
  const groups = clan?.branch_groups ?? [];
  const group = groups.find((g) => g.code === value.group);
  const branchGroups = group ? [group] : groups;

  function changeGroup(code: string) {
    const next = code === ALL ? "" : code;
    const nextGroup = groups.find((g) => g.code === next);
    const keepsBranch = !nextGroup || (nextGroup.branches ?? []).some((b) => b.code === value.branch);
    onChange({ ...value, group: next, branch: keepsBranch ? value.branch : "" });
  }

  // Toolbar fields: the label is a visible inline prefix of each select.
  const field =
    "flex h-9 min-w-0 flex-1 items-center rounded-control bg-surface-2 ring-1 ring-transparent transition-colors focus-within:bg-surface-1 focus-within:ring-ring/60 hover:bg-surface-hover has-[button:disabled]:opacity-60";
  const trigger =
    "h-9! min-w-0 flex-1 border-0 bg-transparent! ps-1.5 pe-2.5 font-medium shadow-none focus-visible:ring-0 hover:bg-transparent";
  const label = "shrink-0 ps-3 text-xs font-normal whitespace-nowrap text-muted-foreground";

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
      <div className={field}>
        <Label htmlFor="dashboard-clan" className={label}>
          العشيرة / العائلة
        </Label>
        <Select value={value.clan || undefined} onValueChange={(code) => onChange({ clan: code, group: "", branch: "" })}>
          <SelectTrigger id="dashboard-clan" className={trigger}>
            <SelectValue placeholder="اختر العشيرة / العائلة" />
          </SelectTrigger>
          <SelectContent>
            {clans.map((c) => (
              <SelectItem key={c.code} value={c.code}>
                {c.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className={field}>
        <Label htmlFor="dashboard-group" className={label}>
          مجموعة الفروع
        </Label>
        <Select value={value.group || ALL} onValueChange={changeGroup} disabled={!clan}>
          <SelectTrigger id="dashboard-group" className={trigger}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>جميع المجموعات</SelectItem>
            {groups.map((g) => (
              <SelectItem key={g.code} value={g.code}>
                {groupLabel(g)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className={field}>
        <Label htmlFor="dashboard-branch" className={label}>
          الفرع
        </Label>
        <Select
          value={value.branch || ALL}
          onValueChange={(code) => onChange({ ...value, branch: code === ALL ? "" : code })}
          disabled={!clan}
        >
          <SelectTrigger id="dashboard-branch" className={trigger}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>جميع الفروع</SelectItem>
            {branchGroups
              .filter((g) => (g.branches ?? []).length > 0)
              .map((g) => (
                <SelectGroup key={g.code}>
                  {g.name ? <SelectLabel>{g.name}</SelectLabel> : <SelectSeparator />}
                  {(g.branches ?? []).map((b) => (
                    <SelectItem key={b.code} value={b.code}>
                      {b.is_active ? b.name : `${b.name} (غير مفعّل)`}
                    </SelectItem>
                  ))}
                </SelectGroup>
              ))}
          </SelectContent>
        </Select>
      </div>
    </div>
  );
}
