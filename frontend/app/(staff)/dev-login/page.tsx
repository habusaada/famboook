import { notFound } from "next/navigation";
import { DevLoginView } from "@/components/dev/dev-login-view";

// Local development only. Outside `next dev`, next.config.ts rewrites this
// path to a non-existent one (a real HTTP 404); notFound() is a second
// layer. The backend's /dev-login route is itself local-only.

export default function DevLoginPage() {
  if (process.env.NODE_ENV !== "development") notFound();

  return <DevLoginView />;
}
