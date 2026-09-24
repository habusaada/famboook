import { AssistanceWorkspace } from "@/components/assistances/assistance-workspace";

export default async function AssistancePage({
  params,
  searchParams,
}: {
  params: Promise<{ assistanceId: string }>;
  searchParams: Promise<{ tab?: string | string[] }>;
}) {
  const { assistanceId } = await params;
  const { tab } = await searchParams;

  return (
    <AssistanceWorkspace
      assistanceId={assistanceId}
      initialTab={typeof tab === "string" ? tab : undefined}
    />
  );
}
