"use client";

import { useState } from "react";
import { AlertCircle, CircleCheck, History, Smartphone } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { StatusBadge } from "@/components/shared/status-badge";
import { DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { GrantMobileTrustDialog } from "@/components/people/grant-mobile-trust-dialog";
import { RevokeMobileTrustDialog } from "@/components/people/revoke-mobile-trust-dialog";
import { usePersonMobileTrust } from "@/lib/api/mobile-trust";
import type { MobileTrustRecord, MobileTrustState } from "@/lib/types/api/mobile-trust";
import { formatDateTime } from "@/lib/utils/date";
import {
  mobileTrustRevokeReasonLabels,
  mobileTrustRowLabels,
  mobileTrustRowTones,
  mobileTrustStateLabels,
  mobileTrustStateTones,
  mobileVerificationMethodLabels,
} from "@/lib/utils/mobile-trust";

// The states in which a new Staff grant is meaningful. TRUSTED is already
// trusted (the API answers ALREADY_TRUSTED); UNAVAILABLE fails closed.
const GRANTABLE: readonly MobileTrustState[] = ["UNVERIFIED", "STALE", "REVOKED", "NO_MOBILE"];

function Masked({ value }: { value: string }) {
  return (
    <bdi dir="ltr" className="tracking-wider tabular-nums">
      {value}
    </bdi>
  );
}

function When({ iso }: { iso: string }) {
  return (
    <bdi dir="ltr" className="tabular-nums">
      {formatDateTime(iso)}
    </bdi>
  );
}

/** One field of a history row; omitted when the API sent nothing. */
function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap gap-x-1.5">
      <dt className="text-muted-foreground">{label}:</dt>
      <dd className="font-medium text-foreground">{children}</dd>
    </div>
  );
}

function HistoryRow({ record }: { record: MobileTrustRecord }) {
  return (
    <li className="flex flex-col gap-2 rounded-lg border p-3" data-mobile-trust-row={record.status}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <StatusBadge tone={mobileTrustRowTones[record.status]}>{mobileTrustRowLabels[record.status]}</StatusBadge>
        <Masked value={record.mobile_masked} />
      </div>
      <dl className="flex flex-col gap-1 text-[13px]">
        <Fact label="طريقة التحقق">
          {record.verification_method ? mobileVerificationMethodLabels[record.verification_method] : "—"}
        </Fact>
        {record.verified_at && (
          <Fact label="تاريخ التوثيق">
            <When iso={record.verified_at} />
          </Fact>
        )}
        {record.verified_by && <Fact label="وثّقه">{record.verified_by}</Fact>}
        {record.stale_at && (
          <Fact label="أصبح متقادمًا في">
            <When iso={record.stale_at} />
          </Fact>
        )}
        {record.revoked_at && (
          <Fact label="تاريخ الإلغاء">
            <When iso={record.revoked_at} />
          </Fact>
        )}
        {record.revoked_by && <Fact label="ألغاه">{record.revoked_by}</Fact>}
        {record.revoke_reason && <Fact label="سبب الإلغاء">{mobileTrustRevokeReasonLabels[record.revoke_reason]}</Fact>}
      </dl>
    </li>
  );
}

/**
 * «توثيق رقم الجوال» (FU-15, docs/06 §22b): the Person's mobile trust as
 * decided by the backend (CurrentTrustedMobile) — never inferred here — with
 * its history, newest first. Shown only to holders of
 * person-mobile-trust.view; grant and revoke are offered by their own
 * permissions. The number appears only as the backend's mask and is never
 * an input. These checks are UX only: the API and the Domain Actions decide.
 */
export function MobileTrustCard({ personCode }: { personCode: string }) {
  const { can } = useAuth();
  const canView = can("person-mobile-trust.view");
  const { data, isLoading, isError } = usePersonMobileTrust(personCode, canView);
  const [notice, setNotice] = useState<string | null>(null);

  if (!canView) return null;

  const trust = data?.data;
  const canGrant = trust !== undefined && can("person-mobile-trust.grant") && GRANTABLE.includes(trust.state);
  const canRevoke = trust !== undefined && can("person-mobile-trust.revoke") && trust.state === "TRUSTED";

  return (
    <AppCard aria-labelledby="person-mobile-trust-title" data-mobile-trust-card>
      <SectionHeader
        title={<span id="person-mobile-trust-title">توثيق رقم الجوال</span>}
        description="توثيق رقم الجوال المسجّل حاليًا للشخص لاستخدامه في العمليات الأمنية لبوابة الأسرة."
        icon={Smartphone}
        tone="neutral"
        action={
          canGrant || canRevoke ? (
            <div className="flex flex-wrap items-center gap-2">
              {canGrant && (
                <GrantMobileTrustDialog
                  personCode={personCode}
                  mobileMasked={trust.mobile_masked}
                  disabled={trust.state === "NO_MOBILE"}
                  onGranted={() => setNotice("وُثّق رقم الجوال المسجّل حاليًا للشخص.")}
                />
              )}
              {canRevoke && (
                <RevokeMobileTrustDialog
                  personCode={personCode}
                  onRevoked={() => setNotice("أُلغي توثيق رقم الجوال. بقي السجل في السجل التاريخي.")}
                />
              )}
            </div>
          ) : undefined
        }
      />

      {isLoading && (
        <div className="mt-3 flex flex-col gap-2" aria-busy="true">
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
        </div>
      )}

      {isError && (
        <Alert variant="destructive" className="mt-3">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل حالة توثيق الجوال</AlertTitle>
          <AlertDescription>حاول تحديث الصفحة لاحقًا.</AlertDescription>
        </Alert>
      )}

      {trust && (
        <div className="mt-3 flex flex-col gap-3">
          {notice && (
            <Alert role="status" data-mobile-trust-notice>
              <CircleCheck className="size-4" />
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          )}

          <DetailList className="sm:grid-cols-1">
            <DetailItem label="الحالة الحالية">
              <span data-mobile-trust-state={trust.state}>
                <StatusBadge tone={mobileTrustStateTones[trust.state]}>{mobileTrustStateLabels[trust.state]}</StatusBadge>
              </span>
            </DetailItem>
            <DetailItem label="رقم الجوال المسجّل حاليًا">
              {trust.mobile_masked ? (
                <Masked value={trust.mobile_masked} />
              ) : (
                <span className="font-normal text-muted-foreground">غير مسجّل</span>
              )}
            </DetailItem>
          </DetailList>

          {trust.state === "NO_MOBILE" && canGrant && (
            <p className="text-[13px] text-muted-foreground" data-mobile-trust-no-mobile>
              لا يمكن التوثيق قبل تسجيل رقم جوال صالح للشخص. حدّث رقم الجوال أولًا ثم وثّقه.
            </p>
          )}
          {trust.state === "UNAVAILABLE" && (
            <p className="text-[13px] text-muted-foreground" data-mobile-trust-unavailable>
              تعذّر تحديد حالة التوثيق حاليًا بسبب إعدادات الأمان في الخادم. لا تتوفر إجراءات التوثيق إلى حين معالجة ذلك.
            </p>
          )}

          <section aria-labelledby="person-mobile-trust-history-title" className="flex flex-col gap-2">
            <h3 id="person-mobile-trust-history-title" className="flex items-center gap-1.5 text-sm font-semibold">
              <History className="size-4 text-muted-foreground" aria-hidden />
              سجل التوثيق
            </h3>
            {trust.history.length > 0 ? (
              <ol className="flex flex-col gap-2" data-mobile-trust-history>
                {trust.history.map((record) => (
                  <HistoryRow key={record.id} record={record} />
                ))}
              </ol>
            ) : (
              <p className="text-[13px] text-muted-foreground" data-mobile-trust-history-empty>
                لا توجد سجلات توثيق سابقة لهذا الشخص.
              </p>
            )}
          </section>
        </div>
      )}
    </AppCard>
  );
}
