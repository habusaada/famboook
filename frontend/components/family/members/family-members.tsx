"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, ChevronLeft, RotateCw, UserPlus, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { MemberCard } from "@/components/family/members/member-card";
import { MemberDetailSheet } from "@/components/family/members/member-detail-sheet";
import { useFamilyUser } from "@/components/family/family-context";
import { isAccessFailure, useFamilyHouseholdHealthQuery, useFamilyMembersQuery } from "@/lib/api/family-household";
import { registryChildrenEntryVisible } from "@/lib/api/family-registry-admission";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

function MembersSkeleton() {
  return (
    <div className="flex flex-col gap-4" data-members-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل أفراد الأسرة
      </p>
      {[0, 1, 2].map((i) => (
        <div key={i} className={card} aria-hidden>
          <Skeleton className="h-5 w-40" />
          <Skeleton className="mt-2 h-4 w-20" />
          <Skeleton className="mt-2.5 h-3.5 w-40" />
        </div>
      ))}
    </div>
  );
}

function MembersError({ onRetry, retrying }: { onRetry: () => void; retrying: boolean }) {
  return (
    <section className={`${card} flex flex-col items-center gap-3 py-6 text-center`} data-members-error>
      <AlertCircle className="size-6 text-danger" aria-hidden />
      <p className="text-sm font-medium text-foreground" role="alert">
        تعذّر تحميل أفراد الأسرة
      </p>
      <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
        <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
        إعادة المحاولة
      </Button>
    </section>
  );
}

/**
 * The way to «الأبناء والبنات في السجل المدني» (FB-FAMILY-ADD-01 ADD-WP-08).
 * A plain link, shown only when /me says the service is open for this
 * account (`registry_admission`, registryChildrenEntryVisible). The suggested children are read on their own page only, because
 * every read is audited and counted by the server.
 */
function RegistryChildrenEntry() {
  return (
    <section className={card} aria-labelledby="members-registry-children-title" data-registry-children-entry>
      <h2 id="members-registry-children-title" className="text-base font-semibold text-foreground">
        الأبناء والبنات في السجل المدني
      </h2>
      <p className="mt-1 text-sm text-muted-foreground">اعرض أبناءك وبناتك المسجلين في السجل المدني، وأضف المتاح منهم إلى أفراد الأسرة بعد الإقرار.</p>
      <Link
        href="/family/members/registry-children"
        className="mt-3 flex min-h-11 w-full items-center justify-between gap-3 rounded-xl border border-border px-3.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
        data-registry-children-link
      >
        <span className="flex items-center gap-2">
          <UserPlus className="size-4" aria-hidden />
          عرض الأبناء والبنات المقترحين
        </span>
        <ChevronLeft className="size-4" aria-hidden />
      </Link>
    </section>
  );
}

/**
 * أفراد الأسرة (PWA-3A Step 3): one row per active membership of the
 * signed-in head's own household, read-only, exactly in the server's order.
 * The count is the number of rows — the same population as the dashboard's
 * registered count. A 401 or 403 is handled by the session/access flow (the
 * gate and the shell), any other failure inline.
 */
export function FamilyMembers() {
  const user = useFamilyUser();
  const query = useFamilyMembersQuery();
  const data = query.data;
  // The member whose registry details are open (PWA-3B.3), by its opaque
  // member_ref (FU-13) and read from the loaded list itself — no URL, no
  // extra request. If the list no longer has it, the sheet simply closes.
  const [selectedRef, setSelectedRef] = useState<string | null>(null);
  const selected = data?.members.find((member) => member.member_ref === selectedRef) ?? null;
  // Which members have registered health data (PWA-3B.6), by member_ref; the
  // chip waits for the health query and simply does not show if it fails.
  const health = useFamilyHouseholdHealthQuery();
  const withHealth = new Set(health.data?.members.map((member) => member.member_ref) ?? []);

  return (
    <div className="flex flex-col gap-5">
      <header>
        <Link
          href="/family/household"
          className="-ms-2 inline-flex min-h-10 items-center gap-1 rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-ring"
          data-members-back
        >
          <ArrowRight className="size-4" aria-hidden />
          أسرتي
        </Link>
        <h1 className="mt-1 text-2xl leading-snug font-bold text-foreground">أفراد الأسرة</h1>
        {/* Metadata under the title: the number of rows and the family code. */}
        {data ? (
          <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground" data-members-meta>
            <span data-members-count>
              أفراد الأسرة المسجلون (<span className="tabular-nums">{data.members.length}</span>)
            </span>
            <span className="text-subtle-foreground" aria-hidden>
              ·
            </span>
            <span dir="ltr" className="rounded-md bg-brand-50 px-2 py-0.5 font-mono text-[13px] font-medium text-brand-800" data-family-code>
              {data.family_code}
            </span>
          </div>
        ) : (
          query.isPending && (
            <div className="mt-2 flex items-center gap-2" aria-hidden>
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-6 w-24 rounded-md" />
            </div>
          )
        )}
      </header>

      <div aria-busy={query.isPending} data-members>
        {data ? (
          <div className="flex flex-col gap-4">
            {data.members.length === 0 ? (
              <section className={`${card} flex flex-col items-center gap-2 py-8 text-center`} data-members-empty>
                <UsersRound className="size-6 text-subtle-foreground" aria-hidden />
                <p className="text-sm text-muted-foreground">لا يوجد أفراد مسجّلون لهذه الأسرة حاليًا.</p>
              </section>
            ) : (
              <ul className="flex flex-col gap-3" aria-label="أفراد الأسرة">
                {/* Server order, never re-sorted; keyed by member_ref, which never reaches the DOM. */}
                {data.members.map((member) => (
                  <MemberCard
                    key={member.member_ref}
                    member={member}
                    // An unavailable Person has no details to show.
                    onShowDetails={member.available ? () => setSelectedRef(member.member_ref) : undefined}
                    hasHealth={withHealth.has(member.member_ref)}
                  />
                ))}
              </ul>
            )}
            {registryChildrenEntryVisible(user) && <RegistryChildrenEntry />}
          </div>
        ) : query.isError ? (
          !isAccessFailure(query.error) && <MembersError onRetry={() => query.refetch()} retrying={query.isFetching} />
        ) : (
          <MembersSkeleton />
        )}
      </div>

      <MemberDetailSheet member={selected} onClose={() => setSelectedRef(null)} />
    </div>
  );
}
