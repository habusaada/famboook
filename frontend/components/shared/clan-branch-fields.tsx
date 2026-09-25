"use client";

import { useEffect } from "react";
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
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { useClans } from "@/lib/api/clans";
import type { FamilyBranchRef, FamilyClanRef } from "@/lib/types/api/clan";

// Radix Select cannot use "" as an item value.
const NO_BRANCH = "__none__";

/**
 * Clan (required) + Branch (optional) selectors for a Family
 * (docs/03 §7a). Branches are limited to the selected Clan and grouped by
 * Branch Group; unnamed groups are shown without a heading. Changing the
 * Clan clears a Branch that does not belong to it. Only active items can
 * be newly chosen; a Family's current (since-deactivated) Clan or Branch
 * stays visible so an unrelated edit does not drop it.
 */
export function ClanBranchFields({
  idPrefix,
  clanCode,
  branchCode,
  onClanChange,
  onBranchChange,
  clanError,
  branchError,
  current,
  preselectSingleClan = false,
}: {
  idPrefix: string;
  clanCode: string;
  branchCode: string;
  onClanChange: (code: string) => void;
  onBranchChange: (code: string) => void;
  clanError?: string;
  branchError?: string;
  current?: { clan: FamilyClanRef | null; branch: FamilyBranchRef | null };
  preselectSingleClan?: boolean;
}) {
  const { data, isLoading, isError } = useClans();
  const clans = data?.data ?? [];

  // A convenience only: with exactly one active Clan, preselect it.
  const single = preselectSingleClan && clans.length === 1 ? clans[0].code : null;
  useEffect(() => {
    if (single && !clanCode) onClanChange(single);
  }, [single, clanCode, onClanChange]);

  const selectedClan = clans.find((c) => c.code === clanCode);
  const groups = (selectedClan?.branch_groups ?? []).filter((g) => (g.branches ?? []).length > 0);
  const activeBranchCodes = new Set(groups.flatMap((g) => (g.branches ?? []).map((b) => b.code)));

  // The Family's current Branch when it is no longer selectable.
  const keptBranch =
    current?.branch &&
    current.clan?.code === clanCode &&
    !activeBranchCodes.has(current.branch.code)
      ? current.branch
      : null;
  const keptClan =
    current?.clan && !clans.some((c) => c.code === current.clan?.code) ? current.clan : null;

  function changeClan(code: string) {
    onClanChange(code);
    if (!branchCode) return;
    const next = clans.find((c) => c.code === code);
    const belongs =
      (next?.branch_groups ?? []).some((g) => (g.branches ?? []).some((b) => b.code === branchCode)) ||
      (current?.clan?.code === code && current.branch?.code === branchCode);
    if (!belongs) onBranchChange("");
  }

  return (
    <>
      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`${idPrefix}-clan`}>العشيرة / العائلة</FieldLabel>
        <Select value={clanCode || undefined} onValueChange={changeClan} disabled={isLoading}>
          <SelectTrigger id={`${idPrefix}-clan`} className="w-full">
            <SelectValue placeholder={isLoading ? "جارٍ التحميل…" : "اختر العشيرة / العائلة"} />
          </SelectTrigger>
          <SelectContent>
            {clans.map((clan) => (
              <SelectItem key={clan.code} value={clan.code}>
                {clan.name}
              </SelectItem>
            ))}
            {keptClan && (
              <SelectItem value={keptClan.code}>{keptClan.name} (غير مفعّلة)</SelectItem>
            )}
          </SelectContent>
        </Select>
        {isError && (
          <p className="text-xs text-destructive">تعذّر تحميل قائمة العشائر والعائلات.</p>
        )}
        <FieldError message={clanError} />
      </div>

      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`${idPrefix}-branch`} optional>
          الفرع
        </FieldLabel>
        <Select
          value={branchCode || NO_BRANCH}
          onValueChange={(v) => onBranchChange(v === NO_BRANCH ? "" : v)}
          disabled={!clanCode}
        >
          <SelectTrigger id={`${idPrefix}-branch`} className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={NO_BRANCH}>بدون فرع (غير محدد)</SelectItem>
            {keptBranch && (
              <SelectGroup>
                <SelectLabel>الفرع الحالي</SelectLabel>
                <SelectItem value={keptBranch.code}>{keptBranch.name} (غير مفعّل)</SelectItem>
              </SelectGroup>
            )}
            {groups.map((group) => (
              <SelectGroup key={group.code}>
                {group.name ? <SelectLabel>{group.name}</SelectLabel> : <SelectSeparator />}
                {(group.branches ?? []).map((branch) => (
                  <SelectItem key={branch.code} value={branch.code}>
                    {branch.name}
                  </SelectItem>
                ))}
              </SelectGroup>
            ))}
          </SelectContent>
        </Select>
        {clanCode && selectedClan && groups.length === 0 && !keptBranch && (
          <p className="text-xs text-muted-foreground">لا توجد فروع مفعّلة لهذه العشيرة / العائلة بعد.</p>
        )}
        <FieldError message={branchError} />
      </div>
    </>
  );
}
