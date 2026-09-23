import { FamilyProfileView } from "@/components/families/family-profile-view";

export default async function FamilyProfilePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <FamilyProfileView familyCode={id} />;
}
