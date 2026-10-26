import { RequirePermission } from "@/components/auth/require-permission";
import { RunDetail } from "@/components/administration/civil-registry/run-detail";

export default async function CivilImportRunPage({ params }: { params: Promise<{ runId: string }> }) {
  const { runId } = await params;

  return (
    <RequirePermission permission="civil-import.view">
      <RunDetail runId={runId} />
    </RequirePermission>
  );
}
