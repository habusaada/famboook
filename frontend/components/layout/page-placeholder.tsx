import { Construction } from "lucide-react";

export function PagePlaceholder({ title }: { title: string }) {
  return (
    <div className="flex flex-col gap-6">
      <h2 className="text-2xl font-semibold tracking-tight">{title}</h2>
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
        <Construction className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">قيد التطوير</p>
      </div>
    </div>
  );
}
