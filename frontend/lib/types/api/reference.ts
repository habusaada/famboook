// Type mirroring backend/app/Http/Resources/RelationshipTypeResource.php.

export interface RelationshipType {
  id: number;
  code: string;
  name: string;
}

// Type mirroring backend/app/Http/Resources/AssessmentDomainResource.php.
// Identified by its stable code; is_active=false only appears on
// historical results.
export interface AssessmentDomain {
  code: string;
  name: string;
  is_active: boolean;
  sort_order: number;
}

// Type mirroring backend/app/Http/Resources/DisabilityTypeResource.php.
export interface DisabilityType {
  id: number;
  code: string;
  name: string;
}
