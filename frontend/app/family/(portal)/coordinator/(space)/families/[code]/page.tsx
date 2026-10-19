"use client";

import { useParams } from "next/navigation";
import { CoordinatorFamilyDirectory } from "@/components/family/coordinator/coordinator-family-directory";

// The code is only a lookup key: the server answers from the Coordinator's
// authorized query, or 404. The details and members directory (CO-01C) fall
// back to the summary when the directory permission is not held.

export default function CoordinatorFamilyPage() {
  const { code } = useParams<{ code: string }>();

  return <CoordinatorFamilyDirectory code={decodeURIComponent(code)} />;
}
