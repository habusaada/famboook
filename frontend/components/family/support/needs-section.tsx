"use client";

import { ClipboardList, Info } from "lucide-react";
import { Chip, DateText, Fact, Missing, RecordPerson, SectionError, SectionSkeleton, recordCard } from "@/components/family/support/support-parts";
import { type FamilyNeed, isAccessFailure, useFamilyNeedsQuery } from "@/lib/api/family-household";
import { NEED_STATUS_NOTE, NO_NEEDS, needStatusLabels } from "@/lib/utils/family-portal-labels";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

function NeedCard({ need }: { need: FamilyNeed }) {
  return (
    <li className={recordCard} data-need={need.status}>
      <div className="flex items-start justify-between gap-3">
        <p className="min-w-0 text-sm font-semibold break-words text-foreground">
          <bdi>{need.title}</bdi>
        </p>
        <Chip tone={need.status === "OPEN" ? "brand" : "muted"}>{needStatusLabels[need.status]}</Chip>
      </div>
      <dl className="mt-1 divide-y divide-stroke-subtle">
        <Fact label="التصنيف" field="category">
          {need.category.name}
        </Fact>
        <Fact label="الكمية" field="quantity">
          {need.quantity === null ? (
            <Missing />
          ) : (
            <>
              <span className="tabular-nums">{need.quantity}</span>
              {need.unit && <> {need.unit}</>}
            </>
          )}
        </Fact>
        <Fact label="لمن" field="person">
          <RecordPerson person={need.person} />
        </Fact>
        <Fact label="تاريخ التسجيل" field="created_at">
          <DateText value={need.created_at} />
        </Fact>
        {need.status === "FULFILLED" && (
          <Fact label="تاريخ التلبية" field="resolved_at">
            <DateText value={need.resolved_at} />
          </Fact>
        )}
        {need.status === "CLOSED" && (
          <Fact label="تاريخ الإغلاق" field="resolved_at">
            <DateText value={need.resolved_at} />
          </Fact>
        )}
      </dl>
    </li>
  );
}

function NeedGroup({ id, title, needs }: { id: string; title: string; needs: FamilyNeed[] }) {
  if (needs.length === 0) return null;

  return (
    <section aria-labelledby={`needs-${id}-title`} data-needs-group={id}>
      <h3 id={`needs-${id}-title`} className="mb-1.5 text-[13px] font-semibold text-muted-foreground">
        {title}
      </h3>
      <ul className="flex flex-col gap-2">
        {needs.map((need, index) => (
          // Needs carry no identifier; the server order is stable.
          <NeedCard key={index} need={need} />
        ))}
      </ul>
    </section>
  );
}

/**
 * «الاحتياجات المسجّلة» (PWA-3B.7): the household's registered needs, every
 * status — «قائمة» (OPEN) and «منتهية» (FULFILLED / CLOSED). A need's status
 * is kept by the registry and is separate from received assistance:
 * FULFILLED is never presented as a delivery. Read-only; its own loading
 * and error, independent of the assistance section.
 */
export function NeedsSection() {
  const query = useFamilyNeedsQuery();
  const needs = query.data;

  return (
    <section className={card} aria-labelledby="family-needs-title" aria-busy={query.isPending} data-family-needs>
      <h2 id="family-needs-title" className="flex items-center gap-2 text-base font-semibold text-foreground">
        <ClipboardList className="size-4 text-brand-700" aria-hidden />
        الاحتياجات المسجّلة
      </h2>
      {needs ? (
        needs.length === 0 ? (
          <p className="mt-3 text-sm text-muted-foreground" data-needs-empty>
            {NO_NEEDS}
          </p>
        ) : (
          <div className="mt-3 flex flex-col gap-4">
            <NeedGroup id="open" title="قائمة" needs={needs.filter((need) => need.status === "OPEN")} />
            <NeedGroup id="resolved" title="منتهية" needs={needs.filter((need) => need.status !== "OPEN")} />
            <p className="flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground" data-needs-note>
              <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
              {NEED_STATUS_NOTE}
            </p>
          </div>
        )
      ) : query.isError ? (
        !isAccessFailure(query.error) && (
          <SectionError message="تعذّر تحميل الاحتياجات" onRetry={() => query.refetch()} retrying={query.isFetching} />
        )
      ) : (
        <SectionSkeleton label="جارٍ تحميل الاحتياجات" />
      )}
    </section>
  );
}
