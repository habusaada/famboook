"use client";

import { useAuth } from "@/components/auth/auth-context";
import { useFamilyActivities } from "@/lib/api/activity";
import { useFamilyAssessments } from "@/lib/api/assessments";
import { useFamilyHealth } from "@/lib/api/health";
import { useFamilyNeeds } from "@/lib/api/needs";

/**
 * Operational snapshot for the Household 360° view (Quick Facts, Overview).
 * Reuses the tabs' own queries — same keys, same cache — so nothing new is
 * fetched or exposed. Each query runs only with that module's view
 * permission (an empty family code disables it); without the permission
 * the fact is simply absent, never guessed.
 */
export function useFamilySnapshot(familyCode: string) {
  const { can } = useAuth();
  const canHealth = can("health-record.view");
  const canNeeds = can("need.view");
  const canAssessments = can("assessment.view");
  const canActivity = can("activity-log.view");

  const health = useFamilyHealth(canHealth ? familyCode : "");
  const needs = useFamilyNeeds(familyCode, {}, canNeeds);
  const assessments = useFamilyAssessments(canAssessments ? familyCode : "");
  const activity = useFamilyActivities(canActivity ? familyCode : "");

  const healthRecords = health.data?.data ?? [];
  const firstNeedsPage = needs.data?.pages[0];

  return {
    health: canHealth
      ? {
          loading: health.isLoading,
          error: health.isError,
          summary: health.data?.summary,
          activeRecords: healthRecords.filter((r) => r.is_active),
        }
      : null,
    needs: canNeeds
      ? {
          loading: needs.isLoading,
          error: needs.isError,
          summary: firstNeedsPage?.summary,
          open: (needs.data?.pages.flatMap((p) => p.data) ?? []).filter((n) => n.status === "OPEN"),
        }
      : null,
    assessment: canAssessments
      ? {
          loading: assessments.isLoading,
          error: assessments.isError,
          // The API lists the latest first.
          latest: assessments.data?.pages[0]?.data[0] ?? null,
        }
      : null,
    activity: canActivity
      ? {
          loading: activity.isLoading,
          error: activity.isError,
          recent: (activity.data?.pages[0]?.data ?? []).slice(0, 5),
        }
      : null,
  };
}

export type FamilySnapshot = ReturnType<typeof useFamilySnapshot>;
