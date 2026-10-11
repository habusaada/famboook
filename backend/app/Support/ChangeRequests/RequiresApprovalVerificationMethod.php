<?php

namespace App\Support\ChangeRequests;

/**
 * Optional handler capability (FP-ADR-077): approving this type needs the
 * reviewer to state HOW they verified the reported life-status fact outside
 * the system (LifeStatusVerificationMethod; no document upload in V1).
 *
 * ApproveChangeRequestAction refuses approval without a method and records
 * it as the controlled code `verification_method` in the APPROVED event
 * metadata; the handler's apply() reads it back from that event (see
 * approvedVerificationMethod()) and passes it to the canonical Domain Action.
 * A type without this capability takes no method at approval.
 */
interface RequiresApprovalVerificationMethod {}
