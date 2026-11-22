<?php

namespace App\Domains\Enquiry\Enums;

enum EnquiryStatus: string
{
    case New = 'new';
    case UnderReview = 'under_review';
    case QuotationRequested = 'quotation_requested';
    case QuotationsReceived = 'quotations_received';
    case Selected = 'selected';
    case Converted = 'converted';
    case OnHold = 'on_hold';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
