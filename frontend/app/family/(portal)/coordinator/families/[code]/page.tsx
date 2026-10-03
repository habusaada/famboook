"use client";

import { useParams } from "next/navigation";
import { CoordinatorFamilySummary } from "@/components/family/coordinator/coordinator-family-summary";

// The code is only a lookup key: the server answers from the Coordinator's
// authorized query, or 404.

export default function CoordinatorFamilyPage() {
  const { code } = useParams<{ code: string }>();

  return <CoordinatorFamilySummary code={decodeURIComponent(code)} />;
}
