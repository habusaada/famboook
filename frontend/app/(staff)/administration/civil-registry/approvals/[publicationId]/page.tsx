import { RequirePermission } from "@/components/auth/require-permission";
import { PublicationDetail } from "@/components/administration/civil-registry/publications";

export default async function CivilImportPublicationPage({ params }: { params: Promise<{ publicationId: string }> }) {
  const { publicationId } = await params;

  return (
    <RequirePermission permission="civil-import.view">
      <PublicationDetail id={publicationId} />
    </RequirePermission>
  );
}
