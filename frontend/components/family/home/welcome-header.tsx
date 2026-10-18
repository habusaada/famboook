"use client";

import { useEffect, useState } from "react";
import { CalendarClock, History } from "lucide-react";
import type { FamilyUser } from "@/lib/api/family-auth";

// FB-FAMILY-DASHBOARD-01: the compact greeting of the Family home — the
// user's name, the current local date and time, and the previous successful
// sign-in the server resolved for THIS session. Arabic words with Latin
// digits, as elsewhere in the portal; the device's own time zone.

const DATE_TIME = new Intl.DateTimeFormat("ar-u-nu-latn", {
  weekday: "long",
  day: "numeric",
  month: "long",
  year: "numeric",
  hour: "numeric",
  minute: "2-digit",
});

export function formatLocalDateTime(date: Date): string {
  return DATE_TIME.format(date);
}

export const FIRST_SIGN_IN_TEXT = "هذا أول دخول لك إلى بوابة الأسرة.";
export const UNKNOWN_SIGN_IN_TEXT = "لا تتوفر معلومات عن الدخول السابق.";

/**
 * The current time, updated on each minute boundary while mounted. null
 * until mounted, so the server render and the first client render match.
 */
function useNow(): Date | null {
  const [now, setNow] = useState<Date | null>(null);

  useEffect(() => {
    let interval: ReturnType<typeof setInterval> | undefined;
    const tick = () => setNow(new Date());
    tick();
    const toNextMinute = 60_000 - (Date.now() % 60_000);
    const timeout = setTimeout(() => {
      tick();
      interval = setInterval(tick, 60_000);
    }, toNextMinute);

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
          {now ? <time dateTime={now.toISOString()}>{formatLocalDateTime(now)}</time> : <span aria-hidden>&nbsp;</span>}
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
