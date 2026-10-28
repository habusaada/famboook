import { FamilyCivilLookup } from "@/components/family/civil-lookup";

// FB-CIVIL-02B: the Coordinator's Civil Registry Lookup (behind the CoordinatorGate of (space)).
export default function CoordinatorCivilRegistryPage() {
  return <FamilyCivilLookup side="coordinator" />;
}
