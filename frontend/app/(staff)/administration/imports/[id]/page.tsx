import { redirect } from "next/navigation";

// Older batch links open the batch inside the Import Wizard.
export default async function ImportBatchPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  redirect(`/administration/imports?batch=${encodeURIComponent(id)}`);
}
