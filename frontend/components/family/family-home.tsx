"use client";

import Link from "next/link";
import { BadgeCheck, ChevronLeft, Clock3, Network } from "lucide-react";
import { useFamilyUser } from "@/components/family/family-context";
import { InstallFamboook } from "@/components/family/pwa/install-famboook";

const UPCOMING = ["بيانات الأسرة وأفرادها", "طلبات تحديث البيانات", "بطاقة رب الأسرة الرقمية"];

/**
 * The first Family Portal home (PWA-1F): it proves the session and the
 * server-resolved Family context, nothing more. No family data is loaded
 * here — the deeper modules come with their own authorized endpoints.
 */
export function FamilyHome() {
  const user = useFamilyUser();
  const family = user.context.family;

  return (
    <div className="flex flex-col gap-6">
      <section aria-labelledby="family-greeting">
        <p className="text-sm text-muted-foreground">مرحبًا بك</p>
        <h1 id="family-greeting" className="mt-0.5 text-2xl leading-snug font-bold text-foreground" data-family-greeting>
          <bdi>{user.display_name ?? "مستخدم بوابة الأسرة"}</bdi>
        </h1>
      </section>

      {user.coordinator_space && (
        // Shown only when the server says Coordinator Space is open; the space
        // itself asks the server again.
        <Link
          href="/family/coordinator"
          className="flex items-center gap-3 rounded-2xl border border-brand-100 bg-brand-50 p-4 transition-colors hover:bg-brand-100 focus-visible:outline-2 focus-visible:outline-ring"
          data-coordinator-entry
        >
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-700 text-white" aria-hidden>
            <Network className="size-5" />
          </span>
          <span className="min-w-0 flex-1">
            <span className="block text-base font-semibold text-brand-900">مساحة التنسيق</span>
            <span className="mt-0.5 block text-[13px] text-brand-800">متابعة الأسر ضمن نطاق التنسيق المعيّن لك.</span>
          </span>
          <ChevronLeft className="size-5 shrink-0 text-brand-700" aria-hidden />
        </Link>
      )}

      <InstallFamboook />

      {family && (
        <section className="rounded-2xl border border-border bg-surface-1 p-4" aria-label="الأسرة" data-family-card>
          <p className="text-xs font-medium text-subtle-foreground">الأسرة</p>
          <div className="mt-1.5 flex items-center justify-between gap-3">
            <p className="min-w-0 truncate text-base font-semibold text-foreground">
              <bdi>{family.name ?? "أسرتي"}</bdi>
            </p>
            <span dir="ltr" className="shrink-0 rounded-lg bg-brand-50 px-2.5 py-1 font-mono text-[13px] font-medium text-brand-800" data-family-code>
              {family.code}
            </span>
          </div>
        </section>
      )}

      <section className="rounded-2xl border border-border bg-surface-1 p-4" aria-labelledby="family-status-title">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-success-soft text-success" aria-hidden>
            <BadgeCheck className="size-5" />
          </span>
          <div>
            <h2 id="family-status-title" className="text-base font-semibold text-foreground">
              حسابك مفعّل
            </h2>
            <p className="mt-1 text-sm leading-relaxed text-muted-foreground">تم تفعيل حساب الأسرة بنجاح. ستتوفر خدمات البوابة هنا تباعًا.</p>
          </div>
        </div>
      </section>

      <section aria-labelledby="family-upcoming-title">
        <h2 id="family-upcoming-title" className="mb-2 text-sm font-semibold text-foreground">
          قريبًا في البوابة
        </h2>
        <ul className="divide-y divide-stroke-subtle rounded-2xl border border-border bg-surface-1">
          {UPCOMING.map((item) => (
            <li key={item} className="flex items-center gap-3 px-4 py-3.5 text-sm text-muted-foreground">
              <Clock3 className="size-4 shrink-0 text-subtle-foreground" aria-hidden />
              {item}
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
