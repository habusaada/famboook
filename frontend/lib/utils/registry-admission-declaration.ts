// The declaration a household head makes for each child before a direct
// admission (FB-FAMILY-ADD-01; ADR §6). Its wording is a PROPOSAL until gate
// G-02 approves it, and the server accepts only the version configured in
// family_registry_admission.declaration_version, which no API exposes yet.
//
// So there is NO approved declaration here, and the admission form keeps its
// submission disabled. The version is never guessed or hard-coded: an
// approved text and version arrive with their own approved work package.

export type RegistryAdmissionDeclaration = {
  /** Sent as declaration_version; must equal the server's configured version. */
  version: string;
  /** The exact approved Arabic wording shown beside the confirmation. */
  text: string;
};

export const APPROVED_REGISTRY_ADMISSION_DECLARATION: RegistryAdmissionDeclaration | null = null;
