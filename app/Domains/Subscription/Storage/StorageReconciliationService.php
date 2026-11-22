<?php

namespace App\Domains\Subscription\Storage;

use App\Domains\Document\DocumentAttachmentService;
use App\Models\Company;

/**
 * Company-wide storage accounting foundations wrapping document storage services.
 */
final class StorageReconciliationService
{
    public function __construct(
        private readonly DocumentAttachmentService $documents = new DocumentAttachmentService,
    ) {}

    /**
     * @return array{
     *     counted_bytes: int,
     *     attachment_count: int,
     *     soft_deleted_retained_bytes: int,
     *     raw_available_bytes: int,
     *     delta_bytes: int,
     *     alert: bool,
     *     block: bool,
     *     percent_of_allocation: float|null,
     *     mismatches: list<string>
     * }
     */
    public function reconcile(Company $company, ?int $allocatedBytes = null): array
    {
        $usage = $this->documents->storageUsage($company);
        $recon = $this->documents->reconcileStorage($company);

        $counted = (int) $usage['file_bytes'];
        $mismatches = [];
        if ((int) $recon['delta_bytes'] !== 0) {
            $mismatches[] = 'Counted attachment bytes differ from raw available stored file bytes by '.$recon['delta_bytes'].'.';
        }

        $percent = null;
        $alert = false;
        $block = false;
        if ($allocatedBytes !== null && $allocatedBytes > 0) {
            $percent = round(($counted / $allocatedBytes) * 100, 2);
            $alert = $percent >= (float) config('subscription.storage.alert_percent', 80);
            $block = $percent >= (float) config('subscription.storage.block_percent', 100);
        }

        return [
            'counted_bytes' => $counted,
            'attachment_count' => (int) $usage['attachment_count'],
            'soft_deleted_retained_bytes' => (int) $usage['soft_deleted_retained_bytes'],
            'raw_available_bytes' => (int) $recon['raw_available_bytes'],
            'delta_bytes' => (int) $recon['delta_bytes'],
            'alert' => $alert,
            'block' => $block,
            'percent_of_allocation' => $percent,
            'mismatches' => $mismatches,
        ];
    }
}
