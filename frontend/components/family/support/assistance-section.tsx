"use client";

import { HandHeart } from "lucide-react";
import { Chip, DateText, Fact, Missing, RecordPerson, SectionError, SectionSkeleton, recordCard } from "@/components/family/support/support-parts";
import { type FamilyAssistanceItem, type FamilyDelivery, isAccessFailure, useFamilyAssistanceQuery } from "@/lib/api/family-household";
import {
  NO_DELIVERIES,
  NO_ITEM_DETAILS,
  assistanceTypeLabels,
  currencyLabels,
  receiptModeLabels,
} from "@/lib/utils/family-portal-labels";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

/** «{item} — {quantity} {unit}», then «قيمة الوحدة: {value} {currency}» when a value is registered. */
function Item({ item }: { item: FamilyAssistanceItem }) {
  return (
    <li className="flex flex-col gap-0.5 py-1.5 text-[13px]" data-assistance-item>
      <span className="font-medium text-foreground">
        <bdi>{item.item_name}</bdi>
        {" — "}
        {item.quantity === null ? (
          <Missing />
        ) : (
          <>
            <span className="tabular-nums">{item.quantity}</span>
            {item.unit && <> {item.unit}</>}
          </>
        )}
      </span>
      {item.unit_value !== null && (
        <span className="text-muted-foreground" data-assistance-item-value>
          قيمة الوحدة: <span className="tabular-nums">{item.unit_value}</span>
          {item.currency && <> {currencyLabels[item.currency]}</>}
        </span>
      )}
    </li>
  );
}

function DeliveryCard({ delivery }: { delivery: FamilyDelivery }) {
  const { assistance } = delivery;

  return (
    <li className={recordCard} data-delivery>
      <p className="text-sm font-semibold break-words text-foreground">
        <bdi>{assistance.title}</bdi>
      </p>
      <div className="mt-1.5 flex flex-wrap gap-1.5">
        <Chip tone="brand">{assistance.category.name}</Chip>
        <Chip>{assistanceTypeLabels[assistance.type]}</Chip>
      </div>
      <dl className="mt-1 divide-y divide-stroke-subtle">
        <Fact label="الجهة المقدّمة" field="provider_name">
          <bdi>{assistance.provider_name}</bdi>
        </Fact>
        <Fact label="تاريخ الاستلام" field="delivered_at">
          <DateText value={delivery.delivered_at} />
        </Fact>
        <Fact label="المستفيد" field="beneficiary">
          <RecordPerson person={delivery.beneficiary} />
        </Fact>
        <Fact label="استلمها" field="recipient">
          <RecordPerson person={{ member_ref: null, ...delivery.recipient }} />
          <span className="text-muted-foreground"> ({receiptModeLabels[delivery.receipt_mode]})</span>
        </Fact>
      </dl>
      <div className="mt-1 border-t border-stroke-subtle pt-1.5" data-field="items">
        <p className="text-[13px] text-muted-foreground">العناصر</p>
        {assistance.items.length === 0 ? (
          <p className="py-1.5 text-[13px] text-subtle-foreground" data-assistance-no-items>
            {NO_ITEM_DETAILS}
          </p>
        ) : (
          <ul className="divide-y divide-stroke-subtle">
            {assistance.items.map((item, index) => (
              // Items carry no identifier; the package order is stable.
              <Item key={index} item={item} />
            ))}
          </ul>
        )}
      </div>
    </li>
  );
}

/**
 * «المساعدات المستلمة» (PWA-3B.7): assistance the household actually
 * received — non-reversed deliveries only, newest first, each with the full
 * package. Never a nomination, approval, plan or eligibility signal.
 * Read-only; its own loading and error, independent of the needs section.
 */
export function AssistanceSection() {
  const query = useFamilyAssistanceQuery();
  const deliveries = query.data;

  return (
    <section className={card} aria-labelledby="family-assistance-title" aria-busy={query.isPending} data-family-assistance>
      <h2 id="family-assistance-title" className="flex items-center gap-2 text-base font-semibold text-foreground">
        <HandHeart className="size-4 text-brand-700" aria-hidden />
        المساعدات المستلمة
      </h2>
      {deliveries ? (
        deliveries.length === 0 ? (
          <p className="mt-3 text-sm text-muted-foreground" data-deliveries-empty>
            {NO_DELIVERIES}
          </p>
        ) : (
          <ul className="mt-3 flex flex-col gap-2">
            {deliveries.map((delivery, index) => (
              // Deliveries carry no identifier; the server order (newest first) is stable.
              <DeliveryCard key={index} delivery={delivery} />
            ))}
          </ul>
        )
      ) : query.isError ? (
        !isAccessFailure(query.error) && (
          <SectionError message="تعذّر تحميل المساعدات المستلمة" onRetry={() => query.refetch()} retrying={query.isFetching} />
        )
      ) : (
        <SectionSkeleton label="جارٍ تحميل المساعدات المستلمة" />
      )}
    </section>
  );
}
