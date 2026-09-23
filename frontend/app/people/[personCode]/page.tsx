import { PersonProfileView } from "@/components/people/person-profile-view";

export default async function PersonProfilePage({
  params,
}: {
  params: Promise<{ personCode: string }>;
}) {
  const { personCode } = await params;

  return <PersonProfileView personCode={personCode} />;
}
