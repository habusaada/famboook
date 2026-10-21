"use client";

import { useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { AlertCircle, BellRing, Lock, RotateCw, Search, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { FollowupRow, FollowupSummaryStrip } from "@/components/followups/followup-parts";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { ApiError } from "@/lib/api/client";
import {
  FOLLOWUP_LEVELS,
  FOLLOWUP_OUTCOMES,
  FOLLOWUP_STAGES,
  type FollowupFilters,
  type FollowupLevel,
  type FollowupOutcome,
  type FollowupSide,
  type FollowupStage,
  type FollowupStatus,
  followupHref,
  useFollowupSummaryQuery,
  useFollowupsQuery,
} from "@/lib/api/followups";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import { followupErrorMessage, followupOutcomeLabels, followupStageLabels } from "@/lib/utils/followup";

const ALL = "ALL";

function pick<T extends string>(value: string, allowed: readonly T[]): T | undefined {
  return allowed.includes(value as T) ? (value as T) : undefined;
}

/** A two-option segmented control; the state is text and aria-pressed. */
function Segmented<T extends string>({
  label,
  value,
  options,
  onChange,
  field,
}: {
  label: string;
  value: T;
  options: { value: T; label: string }[];
  onChange: (value: T) => void;
  field: string;
}) {
  return (
    <div role="group" aria-label={label} className="inline-flex w-full rounded-lg bg-surface-2 p-1 sm:w-auto" data-followup-segment={field}>
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          aria-pressed={value === option.value}
          onClick={() => onChange(option.value)}
          className={`min-h-10 flex-1 rounded-md px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-ring sm:flex-none ${
            value === option.value ? "bg-card text-foreground shadow-xs" : "text-muted-foreground hover:text-foreground"
          }`}
          data-followup-option={option.value}
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true" data-followups-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل المتابعات
      </p>
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="flex flex-col gap-2 px-4 py-3.5 sm:px-5">
          <Skeleton className="h-4 w-48" />
          <Skeleton className="h-4 w-64" />
          <Skeleton className="h-3 w-32" />
        </div>
      ))}
    </div>
  );
}

/**
 * The follow-up inbox (FB-NOTIFY-01E.3-F3.3), for either side: the side's
 * counters, OPEN / CLOSED, the filters (stage, level, outcome, family code;
 * coordinator: mine / all visible), one server page at a time — state in the
 * URL, every filter applied by the server. A coordinator sees the CURRENT
 * scope only; the server decides what is listed.
 */
export function FollowupList({ side, header, forbiddenMessage }: { side: FollowupSide; header: React.ReactNode; forbiddenMessage: string }) {
  const registry = useRegistrySearch();
  const router = useRouter();
  const pathname = usePathname();
  const status: FollowupStatus = registry.param("status") === "CLOSED" ? "CLOSED" : "OPEN";
  const stage = pick<FollowupStage>(registry.param("stage"), FOLLOWUP_STAGES);
  const level = pick<FollowupLevel>(registry.param("level"), FOLLOWUP_LEVELS);
  const outcome = status === "CLOSED" ? pick<FollowupOutcome>(registry.param("outcome"), FOLLOWUP_OUTCOMES) : undefined;
  const familyCode = registry.param("family_code").trim();
  const ownership = side === "coordinator" && registry.param("ownership") === "mine" ? "mine" : "all";
  const filters: FollowupFilters = {
    status,
    stage,
    level,
    outcome,
    family_code: familyCode || undefined,
    ownership: side === "coordinator" ? ownership : undefined,
    page: registry.page,
  };

  const query = useFollowupsQuery(side, filters);
  const summary = useFollowupSummaryQuery(side);
  const [code, setCode] = useState(familyCode);
  const [syncedCode, setSyncedCode] = useState(familyCode);
  if (familyCode !== syncedCode) {
    setSyncedCode(familyCode);
    setCode(familyCode);
  }

  const rows = query.data?.data ?? [];
  const filtered = Boolean(stage || level || outcome || familyCode);
  const forbidden = query.error instanceof ApiError && query.error.status === 403;

  if (forbidden) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AppCard padded={false}>
          <EmptyState icon={Lock} title={forbiddenMessage} />
        </AppCard>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4" data-followups={side}>
      {header}

      <FollowupSummaryStrip side={side} counts={summary.data} isError={summary.isError} />

      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <Segmented
          label="حالة المتابعة"
          field="status"
          value={status}
          options={[
            { value: "OPEN", label: "المفتوحة" },
            { value: "CLOSED", label: "المغلقة" },
          ]}
          onChange={(value) => registry.setFilter("status", value === "OPEN" ? "" : value)}
        />
        {side === "coordinator" && (
          <Segmented
            label="المسؤولية"
            field="ownership"
            value={ownership}
            options={[
              { value: "all", label: "كل المتابعات الظاهرة" },
              { value: "mine", label: "متابعاتي" },
            ]}
            onChange={(value) => registry.setFilter("ownership", value === "mine" ? "mine" : "")}
          />
        )}
      </div>

      <AppCard padded={false} className="overflow-hidden">
        <div className="flex flex-col gap-2 p-3 sm:flex-row sm:flex-wrap sm:items-center sm:p-4" role="search" aria-label="تصفية المتابعات">
          <Select value={stage ?? ALL} onValueChange={(value) => registry.setFilter("stage", value === ALL ? "" : value)}>
            <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-44" aria-label="المرحلة" data-filter-stage>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>كل المراحل</SelectItem>
              {FOLLOWUP_STAGES.map((s) => (
                <SelectItem key={s} value={s}>
                  {followupStageLabels[s]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={level ?? ALL} onValueChange={(value) => registry.setFilter("level", value === ALL ? "" : value)}>
            <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-44" aria-label="مستوى الإعلان" data-filter-level>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>كل المستويات</SelectItem>
              <SelectItem value="NORMAL">عادي</SelectItem>
              <SelectItem value="IMPORTANT">مهم</SelectItem>
            </SelectContent>
          </Select>
          {status === "CLOSED" && (
            <Select value={outcome ?? ALL} onValueChange={(value) => registry.setFilter("outcome", value === ALL ? "" : value)}>
              <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-48" aria-label="نتيجة الإغلاق" data-filter-outcome>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>كل النتائج</SelectItem>
                {FOLLOWUP_OUTCOMES.map((o) => (
                  <SelectItem key={o} value={o}>
                    {followupOutcomeLabels[o]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          <form
            className="flex w-full gap-2 sm:w-auto"
            onSubmit={(event) => {
              event.preventDefault();
              registry.setFilter("family_code", code.trim());
            }}
          >
            <Label htmlFor={`followup-family-code-${side}`} className="sr-only">
              رمز الأسرة
            </Label>
            <Input
              id={`followup-family-code-${side}`}
              value={code}
              onChange={(event) => setCode(event.target.value)}
              placeholder="رمز الأسرة"
              maxLength={50}
              dir="ltr"
              className="h-11 min-w-0 flex-1 bg-surface-2 text-start sm:w-44"
              data-filter-family-code
            />
            <Button type="submit" variant="outline" className="h-11 shrink-0" aria-label="بحث برمز الأسرة">
              <Search className="size-4" aria-hidden />
            </Button>
          </form>
          {filtered && (
            <Button
              variant="ghost"
              className="h-11 text-muted-foreground"
              onClick={() => {
                // Clears the filters; keeps OPEN / CLOSED and mine / all.
                const keep = new URLSearchParams();
                if (status === "CLOSED") keep.set("status", "CLOSED");
                if (ownership === "mine") keep.set("ownership", "mine");
                const kept = keep.toString();
                router.push(kept ? `${pathname}?${kept}` : pathname, { scroll: false });
              }}
              data-followups-reset
            >
              <X className="size-4" aria-hidden />
              إعادة الضبط
            </Button>
          )}
        </div>

        <div className="border-t border-stroke-subtle" aria-live="polite">
          {query.isPending ? (
            <SkeletonRows />
          ) : query.isError ? (
            <div className="p-4">
              <Alert variant="destructive" data-followups-error>
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل المتابعات</AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-2">
                  {followupErrorMessage(query.error)}
                  <Button size="sm" variant="outline" className="gap-1.5" onClick={() => query.refetch()} disabled={query.isFetching}>
                    <RotateCw className={`size-4 ${query.isFetching ? "animate-spin" : ""}`} aria-hidden />
                    إعادة المحاولة
                  </Button>
                </AlertDescription>
              </Alert>
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon={BellRing}
              title={filtered ? "لا توجد متابعات مطابقة" : status === "OPEN" ? "لا توجد متابعات مفتوحة" : "لا توجد متابعات مغلقة"}
              description={filtered ? "غيّر التصفية أو أعدها لعرض كل المتابعات." : undefined}
            />
          ) : (
            <ul className="flex flex-col divide-y divide-stroke-subtle" aria-label="المتابعات" aria-busy={query.isFetching} data-followup-list>
              {rows.map((f) => (
                <FollowupRow key={f.id} followup={f} side={side} href={followupHref(side, f.id)} />
              ))}
            </ul>
          )}
        </div>

        {query.data && query.data.meta.last_page > 1 && (
          <div className="border-t border-stroke-subtle">
            <RegistryPagination meta={query.data.meta} onPage={registry.setPage} unit="متابعة" />
          </div>
        )}
      </AppCard>
    </div>
  );
}
