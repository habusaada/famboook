"use client";

import { AlertCircle, RotateCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { UNAVAILABLE_MEMBER } from "@/components/family/members/member-card";
import type { FamilyRecordPerson } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { FAMILY_TARGET, NOT_RECORDED } from "@/lib/utils/family-portal-labels";

// Building blocks of «الاحتياجات والمساعدات» (PWA-3B.7).

export const recordCard = "rounded-xl border border-border bg-surface-1 px-3.5 py-3";

export function Missing({ children = NOT_RECORDED }: { children?: string }) {
  return <span className="font-normal text-subtle-foreground">{children}</span>;
}

/** One label/value row inside a record card. */
export function Fact({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-1.5 text-[13px]" data-field={field}>
      <dt className="shrink-0 text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end font-medium break-words text-foreground">{children}</dd>
    </div>
  );
}

export function DateText({ value }: { value: string | null }) {
  return value ? <span className="tabular-nums">{formatDateLong(value)}</span> : <Missing />;
}

/**
 * Whom a record concerns: «الأسرة» for the whole family; otherwise the
 * Person's name as registered (a former or deceased member keeps it), or
 * the unavailable wording for a removed Person.
 */
export function RecordPerson({ person }: { person: FamilyRecordPerson | null }) {
  if (person === null) return <>{FAMILY_TARGET}</>;
  if (!person.available) return <Missing>{UNAVAILABLE_MEMBER}</Missing>;
  if (!person.full_name) return <Missing />;

  return <bdi>{person.full_name}</bdi>;
}

export function Chip({ children, tone = "muted" }: { children: React.ReactNode; tone?: "muted" | "brand" }) {
  return (
    <span
      className={`inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-medium ${
        tone === "brand" ? "bg-brand-50 text-brand-800" : "border border-border bg-muted text-muted-foreground"
      }`}
    >
      {children}
    </span>
  );
}

/** A section's own loading state. */
export function SectionSkeleton({ label }: { label: string }) {
  return (
    <div className="mt-3 flex flex-col gap-2" data-section-loading>
      <p role="status" className="sr-only">
        {label}
      </p>
      <Skeleton className="h-20 w-full rounded-xl" />
      <Skeleton className="h-20 w-full rounded-xl" />
    </div>
  );
}

/** A section's own error with retry: the other section is never affected. */
export function SectionError({ message, onRetry, retrying }: { message: string; onRetry: () => void; retrying: boolean }) {
  return (
    <div className="mt-3 flex flex-col items-start gap-2" data-section-error>
      <p className="flex items-center gap-2 text-sm font-medium text-foreground" role="alert">
        <AlertCircle className="size-4 text-danger" aria-hidden />
        {message}
      </p>
      <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
        <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
        إعادة المحاولة
      </Button>
    </div>
  );
}
