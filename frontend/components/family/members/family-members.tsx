"use client";

import { useState } from "react";
import Link from "next/link";
import { AlertCircle, ArrowRight, RotateCw, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { MemberCard } from "@/components/family/members/member-card";
import { MemberDetailSheet } from "@/components/family/members/member-detail-sheet";
import { type FamilyMember, isAccessFailure, useFamilyMembersQuery } from "@/lib/api/family-household";

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
 * أفراد الأسرة (PWA-3A Step 3): one row per active membership of the
 * signed-in head's own household, read-only, exactly in the server's order.
 * The count is the number of rows — the same population as the dashboard's
 * registered count. A 401 or 403 is handled by the session/access flow (the
 * gate and the shell), any other failure inline.
 */
export function FamilyMembers() {
  const query = useFamilyMembersQuery();
  const data = query.data;
  // The member whose registry details are open (PWA-3B.3): taken from the
  // loaded list itself — no identifier, no URL, no extra request.
  const [selected, setSelected] = useState<FamilyMember | null>(null);

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
                {/* Server order, never re-sorted; rows carry no identifier. */}
                {data.members.map((member, index) => (
                  <MemberCard
                    key={index}
                    member={member}
                    // An unavailable Person has no details to show.
                    onShowDetails={member.available ? () => setSelected(member) : undefined}
                  />
                ))}
              </ul>
            )}
          </div>
        ) : query.isError ? (
          !isAccessFailure(query.error) && <MembersError onRetry={() => query.refetch()} retrying={query.isFetching} />
        ) : (
          <MembersSkeleton />
        )}
      </div>

      <MemberDetailSheet member={selected} onClose={() => setSelected(null)} />
    </div>
  );
}
