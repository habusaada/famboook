"use client";

import { useCallback, useState } from "react";
import { ExternalLink, UserX } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { checkNationalId, duplicateMatches } from "@/lib/api/people";
import type { NationalIdMatch } from "@/lib/types/api/person";

export const DUPLICATE_NATIONAL_ID_MESSAGE = "يوجد شخص مسجل مسبقًا بهذه الهوية.";

/**
 * Exact National ID duplicate state for a creation form (AUTH-ADR-058):
 * `check` runs the POST pre-check when the field is left; `fromError` picks
 * up the server's refusal on submit (the server always enforces the rule).
 * The National ID itself is never shown back.
 */
export function useNationalIdDuplicate() {
  const [matches, setMatches] = useState<NationalIdMatch[]>([]);

  const check = useCallback(async (value: string | undefined) => {
    const nationalId = value?.trim() ?? "";
    if (!nationalId) {
      setMatches([]);
      return;
    }
    try {
      setMatches(await checkNationalId(nationalId));
    } catch {
      // Not decisive: the creation request is checked on the server anyway.
      setMatches([]);
    }
  }, []);

  /** True when `error` was a duplicate refusal (and it is now shown). */
  const fromError = useCallback((error: unknown): boolean => {
    const found = duplicateMatches(error);
    if (found === null) return false;
    setMatches(found);
    return true;
  }, []);

  return { matches, check, fromError, clear: () => setMatches([]) };
}

/** The warning with safe references (codes, name, family) to inspect. */
export function NationalIdDuplicateNotice({ matches }: { matches: NationalIdMatch[] }) {
  if (matches.length === 0) return null;

  return (
    <Alert variant="destructive" data-duplicate-national-id>
      <UserX className="size-4" />
      <AlertTitle>{DUPLICATE_NATIONAL_ID_MESSAGE}</AlertTitle>
      <AlertDescription className="flex flex-col gap-2">
        <span>لا يمكن إنشاء شخص جديد بالهوية نفسها. راجع السجل الموجود قبل المتابعة:</span>
        <ul className="flex flex-col gap-1.5">
          {matches.map((match) => (
            <li key={match.person_code} className="flex flex-wrap items-center gap-x-2 gap-y-1" data-duplicate-match={match.person_code}>
              <a
                href={`/people/${match.person_code}`}
                target="_blank"
                rel="noopener"
                className="inline-flex items-center gap-1 font-medium underline"
                dir="ltr"
              >
                {match.person_code}
                <ExternalLink className="size-3" />
              </a>
              {match.full_name && <span>{match.full_name}</span>}
              {match.family && (
                <span className="text-xs">
                  —{" "}
                  {match.family.is_household_head ? "رب الأسرة" : (match.family.relationship ?? "فرد")} في الأسرة{" "}
                  <a href={`/families/${match.family.family_code}`} target="_blank" rel="noopener" className="underline" dir="ltr">
                    {match.family.family_code}
                  </a>
                </span>
              )}
            </li>
          ))}
        </ul>
      </AlertDescription>
    </Alert>
  );
}
