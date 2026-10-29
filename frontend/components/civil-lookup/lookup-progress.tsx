"use client";

// FB-CIVIL-02E — the visible state of a pending lookup request: an
// indeterminate bar (no percentage, no fake stages) and a polite status
// announcement. Under reduced motion the bar is static.

export function LookupProgress({ label }: { label: string }) {
  return (
    <div className="flex flex-col gap-2" data-lookup-progress>
      <div
        role="progressbar"
        aria-label={label}
        aria-busy="true"
        className="relative h-1.5 w-full overflow-hidden rounded-full bg-surface-pressed/70"
      >
        <div className="absolute inset-y-0 right-0 w-2/5 rounded-full bg-brand-600 animate-[lookup-indeterminate_1.2s_ease-in-out_infinite] motion-reduce:w-full motion-reduce:animate-none motion-reduce:opacity-60" />
      </div>
      <p role="status" aria-live="polite" className="text-[13px] text-muted-foreground" data-lookup-status>
        {label}
      </p>
    </div>
  );
}
