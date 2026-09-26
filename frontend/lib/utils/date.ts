/** A system timestamp (ISO 8601) for display, e.g. created_at. */
export function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString("ar", {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

/** Shown wherever a date of birth / age is not recorded (NULL = unknown). */
export const UNKNOWN_LABEL = "غير معروف";

/** A date of birth, or the unknown label — never a placeholder date. */
export function birthDateLabel(birthDate: string | null | undefined): string {
  return birthDate || UNKNOWN_LABEL;
}

/** Age in years from the date of birth, or the unknown label — never a guess. */
export function ageLabel(birthDate: string | null | undefined): string {
  return birthDate ? String(calculateAge(birthDate)) : UNKNOWN_LABEL;
}

export function calculateAge(birthDate: string): number {
  const birth = new Date(birthDate);
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const monthDiff = today.getMonth() - birth.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
    age -= 1;
  }
  return age;
}
