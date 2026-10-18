"use client";

import { useEffect, useState } from "react";
import { CalendarClock, History } from "lucide-react";
import type { FamilyUser } from "@/lib/api/family-auth";

// FB-FAMILY-DASHBOARD-01: the compact greeting of the Family home — the
// user's name, today's date with a live clock (seconds), and the previous
// successful sign-in the server resolved for THIS session (static). Arabic
// words with Latin digits, as elsewhere in the portal; the device's own time
// zone (no timeZone option); 12-hour time with the Arabic ص / م.

const DATE_TIME = new Intl.DateTimeFormat("ar-u-nu-latn", {
  weekday: "long",
  day: "numeric",
  month: "long",
  year: "numeric",
  hour: "numeric",
  minute: "2-digit",
});

const DATE = new Intl.DateTimeFormat("ar-u-nu-latn", { weekday: "long", day: "numeric", month: "long", year: "numeric" });

const CLOCK = new Intl.DateTimeFormat("ar-u-nu-latn", { hour: "numeric", minute: "2-digit", second: "2-digit", hour12: true });

/** A date and time to the minute, e.g. the previous sign-in. */
export function formatLocalDateTime(date: Date): string {
  return DATE_TIME.format(date);
}

/** Today's date in words, e.g. «الأحد، 18 أكتوبر 2026». */
export function formatLocalDate(date: Date): string {
  return DATE.format(date);
}

/** The live clock, e.g. «4:13:05 م». */
export function formatLocalClock(date: Date): string {
  return CLOCK.format(date);
}

export const FIRST_SIGN_IN_TEXT = "هذا أول دخول لك إلى بوابة الأسرة.";
export const UNKNOWN_SIGN_IN_TEXT = "لا تتوفر معلومات عن الدخول السابق.";

/**
 * The current time, updated on each second boundary while mounted (both
 * timers cleared on unmount). null until mounted, so the server render and
 * the first client render match — no hydration mismatch.
 */
function useNow(): Date | null {
  const [now, setNow] = useState<Date | null>(null);

  useEffect(() => {
    let interval: ReturnType<typeof setInterval> | undefined;
    const tick = () => setNow(new Date());
    tick();
    const toNextSecond = 1000 - (Date.now() % 1000);
    const timeout = setTimeout(() => {
      tick();
      interval = setInterval(tick, 1000);
    }, toNextSecond);

    return () => {
      clearTimeout(timeout);
      if (interval) clearInterval(interval);
    };
  }, []);

  return now;
}

function Line({ icon: Icon, label, children, field }: { icon: typeof History; label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start gap-1.5" data-welcome={field}>
      <Icon className="mt-0.5 size-3.5 shrink-0 text-subtle-foreground" aria-hidden />
      <dt className="sr-only">{label}</dt>
      <dd className="min-w-0">{children}</dd>
    </div>
  );
}

export function WelcomeHeader({ user }: { user: FamilyUser }) {
  const now = useNow();
  const previous = user.previous_sign_in_at ?? null;

  return (
    <section aria-labelledby="family-greeting" data-welcome-header>
      <p className="text-sm text-muted-foreground">مرحبًا بك</p>
      <h1 id="family-greeting" className="mt-0.5 text-2xl leading-snug font-bold text-foreground" data-family-greeting>
        <bdi>{user.display_name ?? "مستخدم بوابة الأسرة"}</bdi>
      </h1>
      <dl className="mt-2 flex flex-col gap-1 text-[13px] leading-relaxed text-muted-foreground">
        <Line icon={CalendarClock} label="الوقت الحالي" field="now">
          {now ? (
            <time dateTime={now.toISOString()}>
              {formatLocalDate(now)}
              <span aria-hidden> · </span>
              {/* Tabular digits: the clock never shifts as the seconds change. Not a live region. */}
              <span className="font-medium text-foreground tabular-nums" data-welcome-clock>
                {formatLocalClock(now)}
              </span>
            </time>
          ) : (
            <span aria-hidden>&nbsp;</span>
          )}
        </Line>
        <Line icon={History} label="الدخول السابق" field="previous-sign-in">
          {previous ? (
            <>
              آخر دخول سابق: <time dateTime={previous}>{formatLocalDateTime(new Date(previous))}</time>
            </>
          ) : user.first_sign_in ? (
            FIRST_SIGN_IN_TEXT
          ) : (
            <span className="text-subtle-foreground">{UNKNOWN_SIGN_IN_TEXT}</span>
          )}
        </Line>
      </dl>
    </section>
  );
}
