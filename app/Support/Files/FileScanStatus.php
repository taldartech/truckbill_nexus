<?php

namespace App\Support\Files;

enum FileScanStatus: string
{
    case PendingScan = 'pending_scan';
    case Scanning = 'scanning';
    case Available = 'available';
    case Rejected = 'rejected';

    public function isDownloadable(): bool
    {
        return $this === self::Available;
    }
}
