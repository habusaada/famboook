"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { AlertTriangle, Lock, ServerOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { StatusBadge } from "@/components/shared/status-badge";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { civilImportErrorCode } from "@/lib/api/civil-registry";
import { cn } from "@/lib/utils";
import type { CivilImportPhase } from "@/lib/types/api/civil-registry";
import { PHASE_LABELS, PHASE_TONES } from "@/components/administration/civil-registry/labels";

// FB-CIVIL-01A-UI U1 — shared parts of the read-only civil import screens.

export function PhaseBadge({ phase }: { phase: CivilImportPhase }) {
  return (
    <span className="inline-flex" data-phase={phase}>
      <StatusBadge tone={PHASE_TONES[phase]}>{PHASE_LABELS[phase]}</StatusBadge>
    </span>
  );
}

const SECTIONS = [
  {
    href: "/administration/civil-registry",
    label: "نظرة عامة",
    permission: "civil-import.view",
    exact: true,
  },
  {
    href: "/administration/civil-registry/imports",
    label: "سجل الاستيراد",
    permission: "civil-import.view",
  },
  {
    href: "/administration/civil-registry/releases",
    label: "الإصدارات",
    permission: "civil-import.view",
  },
  {
    href: "/administration/civil-registry/audit",
    label: "سجل التدقيق",
    permission: "civil-import.audit.view",
  },
];

/** Section navigation; each entry appears only with its permission (UX only, the API authorizes). */
export function CivilRegistryNav() {
  const { can } = useAuth();
  const pathname = usePathname() ?? "";

  return (
    <nav aria-label="أقسام السجل المدني" className="flex flex-wrap gap-1.5" data-civil-nav>
      {SECTIONS.filter((s) => can(s.permission)).map((s) => {
        const active = s.exact ? pathname === s.href : pathname.startsWith(s.href);
        return (
          <Link
            key={s.href}
            href={s.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "rounded-control border px-3 py-1.5 text-sm transition-colors",
              active
                ? "border-brand-600 bg-brand-50 font-semibold text-brand-700"
                : "border-stroke-subtle text-foreground hover:bg-surface-hover",
            )}
          >
            {s.label}
          </Link>
        );
      })}
    </nav>
  );
}

/** U2: actions only record requests for the dedicated worker; nothing here publishes a release. */
export function ReadOnlyNote() {
  return (
    <p className="text-[13px] text-muted-foreground" data-read-only-note>
      الإجراءات هنا تسجّل طلبات ينفّذها عامل الاستيراد المخصص، ولا يمكن من هنا نشر إصدار. تُعرض الأعداد والرموز فقط، دون أي بيانات هوية.
    </p>
  );
}

export function LoadingBlock({ rows = 3 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-2" aria-busy="true" aria-label="جارٍ التحميل" data-loading>
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className="h-9 w-full" />
      ))}
    </div>
  );
}

/**
 * Error, forbidden and unavailable states. Messages are fixed Arabic text;
 * nothing from the server response is rendered.
 */
export function MonitorError({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const status = error instanceof ApiError ? error.status : null;
  const code = civilImportErrorCode(error);

  if (status === 403) {
    return (
      <div
        className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground"
        data-forbidden
      >
        <Lock className="size-6" />
        لا تملك صلاحية الوصول إلى هذه المعلومات.
      </div>
    );
  }

  const unavailable = status === 503;
  const message =
    code === "MONITOR_NOT_CONFIGURED"
      ? "لم تُضبط متابعة استيراد السجل المدني على هذا الخادم بعد."
      : unavailable
        ? "متابعة استيراد السجل المدني غير متاحة مؤقتًا."
        : status === 404
          ? "العنصر المطلوب غير موجود."
          : "تعذّر تحميل البيانات.";
  const Icon = unavailable ? ServerOff : AlertTriangle;

  return (
    <div
      className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-10 text-center text-sm"
      data-error={code ?? status ?? "unknown"}
    >
      <Icon className={cn("size-6", unavailable ? "text-muted-foreground" : "text-danger")} />
      <p className="text-foreground">{message}</p>
      {onRetry && status !== 404 && (
        <Button variant="outline" size="sm" onClick={onRetry}>
          إعادة المحاولة
        </Button>
      )}
    </div>
  );
}

/** A count, isolated LTR-safe and formatted the existing way. */
export function Num({ value }: { value: number | null | undefined }) {
  return <bdi className="tabular-nums">{value == null ? "—" : value.toLocaleString("ar")}</bdi>;
}

/** A run or release identifier, shortened for tables; the full value is in the title. */
export function ShortId({ id }: { id: string }) {
  return (
    <bdi dir="ltr" title={id} className="font-mono text-xs tracking-wide">
      {id.slice(0, 8)}
    </bdi>
  );
}

/** The new-import wizard entry, for civil-import.stage holders only (UX; the API authorizes). */
export function NewImportLink() {
  const { can } = useAuth();
  if (!can("civil-import.stage")) return null;

  return (
    <Link
      href="/administration/civil-registry/imports/new"
      className="inline-flex h-8 items-center rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/80"
      data-new-import
    >
      استيراد جديد
    </Link>
  );
}
