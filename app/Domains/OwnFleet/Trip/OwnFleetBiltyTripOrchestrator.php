<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\Trip;
use App\Models\User;
use App\Support\Audit\AuditedTransaction;
use Illuminate\Validation\ValidationException;

/**
 * Single Own Vehicle Bilty submission creates LR + internal Trip atomically.
 */
final class OwnFleetBiltyTripOrchestrator
{
    public function __construct(
        private readonly LorryReceiptService $lorryReceipts = new LorryReceiptService,
        private readonly TripService $trips = new TripService,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{lorry_receipt: LorryReceipt, trip: Trip|null}
     */
    public function create(
        Company $company,
        Branch $branch,
        User $actor,
        array $payload,
        bool $createTrip = true,
    ): array {
        return $this->auditedTransaction->run(function () use ($company, $branch, $actor, $payload, $createTrip): array {
            $lr = $this->lorryReceipts->create($company, $branch, $actor, $payload);

            if (! $createTrip || $lr->workflow_context !== LrWorkflowContext::OwnFleet) {
                return ['lorry_receipt' => $lr, 'trip' => null];
            }

            $tripPayload = [
                'vehicle_id' => (string) $lr->vehicle_id,
                'use_substitute_driver' => (bool) ($payload['use_substitute_driver'] ?? false),
                'substitute_driver_party_id' => $payload['substitute_driver_party_id'] ?? null,
                'expected_end_at' => $payload['expected_end_at'] ?? null,
            ];

            try {
                $trip = $this->trips->createFromLorryReceipt($lr, $actor, $tripPayload);
            } catch (ValidationException $e) {
                throw $e;
            } catch (TripConflictException $e) {
                throw ValidationException::withMessages(['vehicle_id' => $e->getMessage()]);
            } catch (TripDomainException $e) {
                throw ValidationException::withMessages(['vehicle_id' => $e->getMessage()]);
            }

            return ['lorry_receipt' => $lr->fresh(['vehicle', 'trip']), 'trip' => $trip];
        });
    }
}
