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

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="dashboard-clan">العشيرة / العائلة</Label>
        <Select value={value.clan || undefined} onValueChange={(code) => onChange({ clan: code, group: "", branch: "" })}>
          <SelectTrigger id="dashboard-clan" className="w-full">
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

      <div className="flex flex-col gap-1.5">
        <Label htmlFor="dashboard-group">مجموعة الفروع</Label>
        <Select value={value.group || ALL} onValueChange={changeGroup} disabled={!clan}>
          <SelectTrigger id="dashboard-group" className="w-full">
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

      <div className="flex flex-col gap-1.5">
        <Label htmlFor="dashboard-branch">الفرع</Label>
        <Select
          value={value.branch || ALL}
          onValueChange={(code) => onChange({ ...value, branch: code === ALL ? "" : code })}
          disabled={!clan}
        >
          <SelectTrigger id="dashboard-branch" className="w-full">
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
