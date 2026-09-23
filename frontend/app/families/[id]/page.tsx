import { notFound } from "next/navigation";
import { FamilyProfileView } from "@/components/families/family-profile-view";
import { getFamilyByCode } from "@/lib/mock-data/families";

export default async function FamilyProfilePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const family = getFamilyByCode(id);

  if (!family) {
    notFound();
  }

  return <FamilyProfileView family={family} />;
}
