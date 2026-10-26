import { RequirePermission } from "@/components/auth/require-permission";
import { ReleaseDetail } from "@/components/administration/civil-registry/releases";

export default async function CivilImportReleasePage({ params }: { params: Promise<{ releaseId: string }> }) {
  const { releaseId } = await params;

  return (
    <RequirePermission permission="civil-import.view">
      <ReleaseDetail releaseId={releaseId} />
    </RequirePermission>
  );
}
