import { FamiliesRegistry } from "@/components/families/families-registry";
import { families } from "@/lib/mock-data/families";

export default function FamiliesPage() {
  return <FamiliesRegistry families={families} />;
}
