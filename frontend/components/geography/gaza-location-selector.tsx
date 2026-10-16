"use client";

import { useId, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { StatusBadge } from "@/components/shared/status-badge";
import { ApiError } from "@/lib/api/client";
import {
  gazaLocationsKey,
  type GazaLocationSource,
  useGazaGovernorates,
  useGazaLocationChildren,
  useGazaLocationDetails,
} from "@/lib/api/gaza-locations";
import type { GazaLocation, GazaLocationLevel } from "@/lib/types/api/gaza-location";

/**
 * GazaLocationSelector — the reusable, controlled, data-driven Gaza location
 * picker (FB-GEO-01 Stage 4A, confirmation semantics Stage 4B; design
 * docs/audits/FB-GEO-01-STAGE-3A-SELECTOR-DESIGN.md).
 *
 * Governorate → City → Area → Neighborhood → optional Landmark. Governorates
 * come from /governorates; every lower level ONLY from the selected parent's
 * /{uuid}/children. Behaviour is derived from the API data alone (id,
 * parent_id, level, has_children) — never from a place name, and identical
 * for every location.
 *
 * Contract:
 * - `value` is the deepest selected location UUID (any level) or null.
 * - EMPTY field: choosing an item emits its UUID at once; «غير محدد» /
 *   «بدون معلم» on a level emits the parent's UUID.
 * - COMMITTED value (saved, or confirmed earlier): shown read-only with its
 *   path. «تغيير» opens a CANDIDATE selection that emits nothing;
 *   «تأكيد الاختيار» emits the candidate's UUID exactly once; «إلغاء» emits
 *   nothing. The committed value — even an inactive one — stays until a
 *   replacement is confirmed.
 * - Clearing («مسح الاختيار») emits null only after «تأكيد المسح».
 * - Never emits on mount, hydration, fetching, or a single-option list (no
 *   automatic selection). Changing a level clears every level below it.
 * - Inactive locations are never offered; an inactive saved location or
 *   ancestor is shown with «غير مفعّلة».
 * - A failed or unknown saved value never changes the stored value.
 * - `requiredLevel` is presentation only (labels / aria-required); the form
 *   (Zod, UX) and Laravel (authoritative) validate.
 * - `source` picks the API: "staff" (default) or the read-only Family PWA
 *   mirrors ("family", API-D2). Behaviour is identical.
 */

const LEVELS: GazaLocationLevel[] = ["GOVERNORATE", "CITY", "AREA", "NEIGHBORHOOD", "LANDMARK"];

const LABELS: Record<GazaLocationLevel, string> = {
  GOVERNORATE: "المحافظة",
  CITY: "المدينة",
  AREA: "المنطقة",
  NEIGHBORHOOD: "الحي",
  LANDMARK: "المعلم",
};

// Radix Select cannot use "" as an item value.
const NONE = "__none__";

// Touch-sized on mobile, the Staff form height from sm. The important
// modifier is needed: SelectTrigger's own data-[size=default]:h-8 outranks a
// plain height utility (the project's `h-11!` convention).
const TRIGGER = "h-11! w-full sm:h-10!";

function nextLevel(level: GazaLocationLevel): GazaLocationLevel | null {
  return LEVELS[LEVELS.indexOf(level) + 1] ?? null;
}

function sameChain(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((x, i) => x === b[i]);
}

export interface GazaLocationSelectorProps {
  /** The deepest selected location UUID, or null. */
  value: string | null;
  /** Called only on user actions (see the component contract). */
  onChange: (uuid: string | null) => void;
  /** DOM id prefix (several selectors in one form). */
  id?: string;
  /** Visible group label (legend). */
  label?: string;
  disabled?: boolean;
  /** Presentation only: levels up to this one are marked required. */
  requiredLevel?: GazaLocationLevel | null;
  /** Offer the optional LANDMARK level (default true). */
  showLandmark?: boolean;
  /** Field error (RHF / Laravel). */
  error?: string;
  /** Which API serves the reference (default "staff"). */
  source?: GazaLocationSource;
}

interface LevelContext {
  source: GazaLocationSource;
  idPrefix: string;
  chain: string[];
  disabled: boolean;
  requiredLevel: GazaLocationLevel | null;
  showLandmark: boolean;
  invalid: boolean;
  describedBy: string | undefined;
  choose: (depth: number, locationId: string | null) => void;
}

export function GazaLocationSelector({
  value,
  onChange,
  id,
  label = "الموقع الجغرافي",
  disabled = false,
  requiredLevel = null,
  showLandmark = true,
  error,
  source = "staff",
}: GazaLocationSelectorProps) {
  const generatedId = useId();
  const idPrefix = id ?? `gaza-location-${generatedId.replace(/:/g, "")}`;
  const errorId = `${idPrefix}-error`;
  const queryClient = useQueryClient();

  // Immediate selection of an EMPTY field: the chain being built. It applies
  // only while its deepest item IS the controlled value.
  const [edit, setEdit] = useState<string[] | null>(null);
  // A staged replacement of a COMMITTED value (never emitted until confirmed).
  const [replacement, setReplacement] = useState<{ forValue: string; candidate: string[] } | null>(null);
  // A staged clear (null is emitted only after confirmation).
  const [clearing, setClearing] = useState<{ forValue: string } | null>(null);

  const userChain = edit !== null && (edit.at(-1) ?? null) === value ? edit : null;
  const committed = value !== null && userChain === null;
  // Staged states belong to the value they were opened for; a value changed
  // by the form (reset, external update) discards them.
  const activeReplacement = replacement !== null && replacement.forValue === value ? replacement : null;
  const activeClearing = clearing !== null && clearing.forValue === value ? clearing : null;

  const details = useGazaLocationDetails(committed ? value : null, source);
  const saved = committed && details.data?.data.id === value ? details.data.data : undefined;
  const savedInactive = saved ? !saved.is_active || saved.path.some((p) => !p.is_active) : false;

  type View = "edit" | "replace" | "loading" | "error" | "missing" | "saved";
  let view: View;
  let chain: string[] = [];
  if (!committed) {
    view = "edit";
    chain = userChain ?? [];
  } else if (activeReplacement !== null) {
    view = "replace";
    chain = activeReplacement.candidate;
  } else if (saved) {
    view = "saved";
  } else if (details.isError) {
    view = details.error instanceof ApiError && details.error.status === 404 ? "missing" : "error";
  } else {
    view = "loading";
  }

  function choose(depth: number, locationId: string | null) {
    if (disabled) return;
    const next = locationId === null ? chain.slice(0, depth) : [...chain.slice(0, depth), locationId];
    if (sameChain(next, chain)) return;
    if (view === "replace" && activeReplacement !== null) {
      // A candidate only: nothing is emitted until confirmed.
      setReplacement({ forValue: activeReplacement.forValue, candidate: next });
      return;
    }
    setEdit(next);
    onChange(next.at(-1) ?? null);
  }

  function startReplacement() {
    if (value === null) return;
    // An all-active saved chain is the starting candidate; an inactive one
    // cannot be offered again, so the candidate starts empty.
    const candidate = saved && !savedInactive ? [...saved.path.map((p) => p.id), saved.id] : [];
    setClearing(null);
    setReplacement({ forValue: value, candidate });
  }

  function confirmReplacement() {
    if (activeReplacement === null) return;
    const chosen = activeReplacement.candidate.at(-1) ?? null;
    setReplacement(null);
    if (chosen !== null && chosen !== value) onChange(chosen);
  }

  function cancelReplacement() {
    setReplacement(null);
  }

  function requestClear() {
    if (value === null) return;
    setReplacement(null);
    setClearing({ forValue: value });
  }

  function confirmClear() {
    setClearing(null);
    setEdit(null);
    onChange(null);
  }

  // Names for the path line: the saved details, then the cached lists.
  function nameOf(locationId: string, depth: number): string | null {
    if (saved) {
      if (saved.id === locationId) return saved.name;
      const ancestor = saved.path.find((p) => p.id === locationId);
      if (ancestor) return ancestor.name;
    }
    const listKey = depth === 0 ? gazaLocationsKey(source, "governorates") : gazaLocationsKey(source, "children", chain[depth - 1]);
    const list = queryClient.getQueryData<{ data: GazaLocation[] }>(listKey);
    return list?.data.find((item) => item.id === locationId)?.name ?? null;
  }

  const context: LevelContext = {
    source,
    idPrefix,
    chain,
    disabled,
    requiredLevel,
    showLandmark,
    invalid: Boolean(error),
    describedBy: error ? errorId : undefined,
    choose,
  };

  const pathNames = chain.map((locationId, depth) => nameOf(locationId, depth)).filter((n): n is string => n !== null);
  const candidate = activeReplacement?.candidate.at(-1) ?? null;
  const canConfirm = candidate !== null && candidate !== value;

  const clearControls = activeClearing ? (
    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="تأكيد مسح الموقع">
      <p className="text-xs text-muted-foreground">سيُمسح الموقع الجغرافي المحدد. النص المسجّل لا يتأثر.</p>
      <Button type="button" variant="destructive" size="sm" onClick={confirmClear} disabled={disabled}>
        تأكيد المسح
      </Button>
      <Button type="button" variant="ghost" size="sm" onClick={() => setClearing(null)} disabled={disabled}>
        إلغاء
      </Button>
    </div>
  ) : null;

  const changeOrClear = (
    <div className="flex flex-wrap items-center gap-2">
      <Button type="button" variant="outline" size="sm" onClick={startReplacement} disabled={disabled}>
        تغيير
      </Button>
      <Button type="button" variant="ghost" size="sm" onClick={requestClear} disabled={disabled}>
        مسح الاختيار
      </Button>
    </div>
  );

  return (
    <fieldset className="flex min-w-0 flex-col gap-3" aria-describedby={error ? errorId : undefined}>
      <legend className="mb-1 text-sm font-medium">{label}</legend>

      {view === "loading" && (
        <p className="text-sm text-muted-foreground" aria-live="polite" aria-busy="true">
          جارٍ تحميل الموقع المحفوظ…
        </p>
      )}

      {view === "error" && (
        <div className="flex flex-wrap items-center gap-2" role="alert">
          <p className="text-sm text-destructive">تعذّر تحميل الموقع المحفوظ. لم يتغيّر الموقع المسجّل.</p>
          <Button type="button" variant="outline" size="sm" onClick={() => details.refetch()} disabled={disabled}>
            <RotateCcw />
            إعادة المحاولة
          </Button>
        </div>
      )}

      {view === "missing" && (
        <div className="flex flex-col gap-2" role="status">
          <p className="text-sm text-muted-foreground">الموقع المحفوظ غير متاح. لم يتغيّر الموقع المسجّل.</p>
          {clearControls ?? changeOrClear}
        </div>
      )}

      {view === "saved" && saved && (
        <div className="flex flex-col gap-2 rounded-lg border border-border p-3" role="group" aria-label="الموقع المحفوظ">
          <GazaLocationPathLine
            items={[...saved.path, { id: saved.id, level: saved.level, name: saved.name, is_active: saved.is_active }]}
            showStatus
          />
          {savedInactive && (
            <p className="text-xs text-muted-foreground">
              هذا الموقع أو أحد مستوياته الأعلى غير مفعّل، فلا يمكن اختياره من جديد. يبقى محفوظًا كما هو حتى يُؤكَّد اختيار موقع آخر.
            </p>
          )}
          {clearControls ?? changeOrClear}
        </div>
      )}

      {(view === "edit" || view === "replace") && (
        <>
          {view === "replace" && (
            <p className="text-xs text-muted-foreground" role="status">
              اختيار موقع بديل — يبقى الموقع المحفوظ دون تغيير حتى تؤكّد الاختيار.
            </p>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <LevelField depth={0} parent={null} context={context} />
          </div>

          {pathNames.length > 0 && <GazaLocationPathLine items={pathNames.map((name, i) => ({ id: chain[i], name }))} />}

          {view === "replace" && (
            <div className="flex flex-wrap items-center gap-2">
              <Button type="button" size="sm" onClick={confirmReplacement} disabled={disabled || !canConfirm}>
                تأكيد الاختيار
              </Button>
              <Button type="button" variant="ghost" size="sm" onClick={cancelReplacement} disabled={disabled}>
                إلغاء
              </Button>
            </div>
          )}

          {view === "edit" && value !== null && (clearControls ?? (
            <div>
              <Button type="button" variant="ghost" size="sm" onClick={requestClear} disabled={disabled}>
                مسح الاختيار
              </Button>
            </div>
          ))}
        </>
      )}

      <div id={errorId}>
        <FieldError message={error} />
      </div>
    </fieldset>
  );
}

/** One level: its list (governorates or the parent's direct children), then the next level. */
function LevelField({ depth, parent, context }: { depth: number; parent: GazaLocation | null; context: LevelContext }) {
  const governorates = useGazaGovernorates(context.source);
  const children = useGazaLocationChildren(parent?.id ?? null, context.source);
  const query = parent === null ? governorates : children;

  const level: GazaLocationLevel | null = parent === null ? "GOVERNORATE" : nextLevel(parent.level);
  if (level === null) return null;

  // Rendering guard: only DIRECT children of THIS parent are ever shown.
  const parentId = parent?.id ?? null;
  const items = (query.data?.data ?? []).filter((item) => item.parent_id === parentId);
  const selectedId = context.chain[depth] ?? null;
  const selected = items.find((item) => item.id === selectedId) ?? null;

  const fieldId = `${context.idPrefix}-${level.toLowerCase()}`;
  const required =
    context.requiredLevel !== null && LEVELS.indexOf(level) <= LEVELS.indexOf(context.requiredLevel);
  const following = selected?.has_children ? nextLevel(selected.level) : null;
  const offerFollowing = following !== null && (following !== "LANDMARK" || context.showLandmark);
  const pendingNext = selectedId === null ? nextLevel(level) : null;
  const showPlaceholder = pendingNext !== null && (pendingNext !== "LANDMARK" || context.showLandmark);

  let control: React.ReactNode;
  if (query.isPending) {
    // Keyed apart from the loaded Select below: a different instance, so
    // Radix never sees one Select switch from uncontrolled to controlled.
    control = (
      <Select key="loading" dir="rtl" disabled>
        <SelectTrigger id={fieldId} className={TRIGGER} aria-busy="true">
          <SelectValue placeholder="جارٍ التحميل…" />
        </SelectTrigger>
      </Select>
    );
  } else if (query.isError) {
    const unavailable = query.error instanceof ApiError && query.error.status === 404;
    control = (
      <div className="flex flex-wrap items-center gap-2" role="alert">
        <p className="text-xs text-destructive">
          {unavailable ? "هذا المستوى غير متاح." : `تعذّر تحميل قائمة ${LABELS[level]}.`}
        </p>
        {!unavailable && (
          <Button type="button" variant="outline" size="sm" onClick={() => query.refetch()} disabled={context.disabled}>
            <RotateCcw />
            إعادة المحاولة
          </Button>
        )}
      </div>
    );
  } else if (items.length === 0) {
    control = <p className="text-xs text-muted-foreground">لا توجد خيارات متاحة في هذا المستوى.</p>;
  } else {
    control = (
      <Select
        key="loaded"
        dir="rtl"
        value={selectedId ?? ""}
        onValueChange={(v) => context.choose(depth, v === NONE ? null : v)}
        disabled={context.disabled}
      >
        <SelectTrigger
          id={fieldId}
          className={TRIGGER}
          aria-required={required || undefined}
          aria-invalid={context.invalid || undefined}
          aria-describedby={context.describedBy}
        >
          <SelectValue placeholder={`اختر ${LABELS[level]}`} />
        </SelectTrigger>
        <SelectContent>
          {depth > 0 && <SelectItem value={NONE}>{level === "LANDMARK" ? "بدون معلم" : "غير محدد"}</SelectItem>}
          {items.map((item) => (
            <SelectItem key={item.id} value={item.id}>
              {item.landmark_type ? `${item.name} (${item.landmark_type})` : item.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    );
  }

  return (
    <>
      <div className="flex min-w-0 flex-col gap-1.5">
        <FieldLabel htmlFor={fieldId} optional={!required}>
          {LABELS[level]}
        </FieldLabel>
        {control}
      </div>
      {selected !== null && offerFollowing && <LevelField depth={depth + 1} parent={selected} context={context} />}
      {showPlaceholder && pendingNext !== null && (
        <div className="flex min-w-0 flex-col gap-1.5">
          <FieldLabel htmlFor={`${context.idPrefix}-${pendingNext.toLowerCase()}`} optional>
            {LABELS[pendingNext]}
          </FieldLabel>
          <Select dir="rtl" disabled>
            <SelectTrigger id={`${context.idPrefix}-${pendingNext.toLowerCase()}`} className={TRIGGER}>
              <SelectValue placeholder={`اختر ${LABELS[level]} أولًا`} />
            </SelectTrigger>
          </Select>
        </div>
      )}
    </>
  );
}

/**
 * The geographic path, general → specific (RTL: the governorate on the
 * right). With `showStatus`, inactive items carry «غير مفعّلة».
 */
export function GazaLocationPathLine({
  items,
  showStatus = false,
}: {
  items: { id: string; name: string; is_active?: boolean }[];
  showStatus?: boolean;
}) {
  return (
    <ol className="flex flex-wrap items-center gap-1 text-sm" aria-label="المسار الجغرافي">
      {items.map((item, index) => (
        <li key={item.id} className="flex items-center gap-1">
          {index > 0 && <ChevronLeft className="size-3.5 text-muted-foreground" aria-hidden="true" />}
          <span className="font-medium">{item.name}</span>
          {showStatus && item.is_active === false && <StatusBadge tone="warning">غير مفعّلة</StatusBadge>}
        </li>
      ))}
    </ol>
  );
}
