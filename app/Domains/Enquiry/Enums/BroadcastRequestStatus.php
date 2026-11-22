<?php

namespace App\Domains\Enquiry\Enums;

/**
 * Request persistence status only — not provider delivery (ADR-013 / Phase 9).
 */
enum BroadcastRequestStatus: string
{
    case Queued = 'queued';
    case Failed = 'failed';
}
