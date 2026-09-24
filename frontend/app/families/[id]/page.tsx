import { FamilyProfileView } from "@/components/families/family-profile-view";

export default async function FamilyProfilePage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ tab?: string | string[] }>;
}) {
  const { id } = await params;
  const { tab } = await searchParams;

  return (
    <FamilyProfileView
      familyCode={id}
      initialTab={typeof tab === "string" ? tab : undefined}
    />
  );
}
