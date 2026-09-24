"use client";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useNeedCategories } from "@/lib/api/reference";
import type { NeedFilters as Filters, NeedPriority, NeedStatus } from "@/lib/types/api/need";
import {
  FAMILY_TARGET_LABEL,
  NEED_PRIORITIES,
  NEED_STATUSES,
  needPriorityLabels,
  needStatusLabels,
} from "@/lib/utils/need";

// Radix Select cannot use "" as a value.
const ALL = "ALL";

function FilterSelect({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string | undefined;
  onChange: (value: string | undefined) => void;
  options: { value: string; label: string }[];
}) {
  return (
    <Select value={value ?? ALL} onValueChange={(v) => onChange(v === ALL ? undefined : v)}>
      <SelectTrigger size="sm" aria-label={label} className="min-w-36">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={ALL}>{label}: الكل</SelectItem>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

export function NeedFilterBar({
  filters,
  onChange,
  showTarget = false,
}: {
  filters: Filters;
  onChange: (filters: Filters) => void;
  showTarget?: boolean;
}) {
  const categories = useNeedCategories().data?.data ?? [];

  return (
    <div className="flex flex-wrap items-center gap-2">
      <FilterSelect
        label="الحالة"
        value={filters.status}
        onChange={(v) => onChange({ ...filters, status: v as NeedStatus | undefined })}
        options={NEED_STATUSES.map((s) => ({ value: s, label: needStatusLabels[s] }))}
      />
      <FilterSelect
        label="الأولوية"
        value={filters.priority}
        onChange={(v) => onChange({ ...filters, priority: v as NeedPriority | undefined })}
        options={[...NEED_PRIORITIES].reverse().map((p) => ({ value: p, label: needPriorityLabels[p] }))}
      />
      <FilterSelect
        label="التصنيف"
        value={filters.category}
        onChange={(v) => onChange({ ...filters, category: v })}
        options={categories.map((c) => ({ value: c.code, label: c.name }))}
      />
      {showTarget && (
        <FilterSelect
          label="المستفيد"
          value={filters.target}
          onChange={(v) => onChange({ ...filters, target: v as Filters["target"] })}
          options={[
            { value: "family", label: FAMILY_TARGET_LABEL },
            { value: "person", label: "فرد من الأسرة" },
          ]}
        />
      )}
    </div>
  );
}
