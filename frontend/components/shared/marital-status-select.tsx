"use client";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { MARITAL_STATUSES, maritalStatusLabels, type MaritalStatus } from "@/lib/utils/marital-status";

/** persons.marital_status. "غير معروف" is the honest default — never guessed. */
export function MaritalStatusSelect({
  id,
  value,
  onChange,
}: {
  id: string;
  value: MaritalStatus;
  onChange: (value: MaritalStatus) => void;
}) {
  return (
    <Select value={value} onValueChange={(v) => onChange(v as MaritalStatus)}>
      <SelectTrigger id={id}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {MARITAL_STATUSES.map((s) => (
          <SelectItem key={s} value={s}>
            {maritalStatusLabels[s]}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
