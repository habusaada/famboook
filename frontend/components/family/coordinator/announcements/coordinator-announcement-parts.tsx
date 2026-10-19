import { Info } from "lucide-react";

/**
 * Shown on the Coordinator announcement pages while /family/me reports no
 * current scope (coordinator_space false) for an account that still holds the
 * COORDINATOR role: the own history stays readable and unpublished messages
 * can still be withdrawn; nothing can be published or retried.
 */
export function NoCurrentScopeNotice() {
  return (
    <p className="flex items-start gap-2 rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-foreground" role="note" data-coordinator-no-scope>
      <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
      <span>
        لا يوجد لديك نطاق تنسيق حالي. تبقى إعلاناتك السابقة متاحة للاطلاع، ويمكنك إلغاء ما لم يُنشر منها، لكن لا يمكن طلب النشر أو إعادة المحاولة.
      </span>
    </p>
  );
}
