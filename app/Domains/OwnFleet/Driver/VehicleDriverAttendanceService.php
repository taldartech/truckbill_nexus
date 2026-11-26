<?php

namespace App\Domains\OwnFleet\Driver;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Enums\DriverAttendanceStatus;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use App\Models\VehicleDriverAttendance;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Legacy-aligned OwnFleet Driver Attendance exception workflow.
 *
 * Working days ("Present") are implicit from active Vehicle Driver assignments.
 * Operators only record exceptions: Absent (with optional Substitute Driver) or No Work Day.
 * Reuses existing Driver assignments and open Trips — no HR/payroll system.
 */
final class VehicleDriverAttendanceService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * Daily attendance overview for all branch-visible Own Vehicles on a date.
     *
     * @return array{
     *     date: string,
     *     summary: array{total: int, present: int, absent: int, substitute: int, no_work_day: int, unassigned: int},
     *     rows: list<array<string, mixed>>,
     *     busy_driver_ids: array<string, string>
     * }
     */
    public function dailyOverview(Company $company, Branch $branch, ?string $date = null): array
    {
        $targetDate = $this->normalizeDate($company, $date);

        $vehicles = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->where('ownership', VehicleOwnership::Own->value)
            ->whereNull('deleted_at')
            ->with(['primaryDriver:id,name,contact_number'])
            ->orderBy('registration_number')
            ->get();

        $assignments = VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->activePrimaryOn($targetDate)
            ->with('driver:id,name,contact_number')
            ->get()
            ->keyBy('vehicle_id');

        $exceptions = VehicleDriverAttendance::query()
            ->where('company_id', $company->id)
            ->whereDate('attendance_date', $targetDate)
            ->whereNull('deleted_at')
            ->with(['primaryDriver:id,name,contact_number', 'temporaryDriver:id,name,contact_number'])
            ->get()
            ->keyBy('vehicle_id');

        $openTrips = Trip::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [TripStatus::Pending->value, TripStatus::InProgress->value])
            ->whereNull('deleted_at')
            ->with(['vehicle:id,registration_number', 'lorryReceipt:id,lr_number'])
            ->get();

        /** @var array<string, string> $busyDrivers */
        $busyDrivers = [];
        /** @var array<string, array{lr_number: string|null, status: string}> $vehicleTripMap */
        $vehicleTripMap = [];

        foreach ($openTrips as $trip) {
            if ($trip->vehicle_id !== '') {
                $vehicleTripMap[$trip->vehicle_id] = [
                    'lr_number' => $trip->lorryReceipt?->lr_number,
                    'status' => $trip->status->value,
                ];
            }
            $tripStart = $trip->departure_at?->toDateString() ?? $trip->created_at?->toDateString();
            $tripEnd = $trip->actual_arrival_at?->toDateString();
            if ($tripStart !== null && $tripStart <= $targetDate && ($tripEnd === null || $tripEnd >= $targetDate)) {
                $label = $trip->lorryReceipt?->lr_number
                    ? "On Trip ({$trip->lorryReceipt->lr_number} · {$trip->vehicle?->registration_number})"
                    : "On Trip ({$trip->vehicle?->registration_number})";
                foreach (array_filter([(string) $trip->driver_party_id, (string) $trip->substitute_driver_party_id]) as $busyId) {
                    $busyDrivers[$busyId] = $label;
                }
            }
        }

        foreach ($exceptions as $exception) {
            if ($exception->temporary_driver_party_id !== null) {
                $busyDrivers[$exception->temporary_driver_party_id] = "Substitute on {$exception->vehicle?->registration_number}";
            }
        }

        $summary = [
            'total' => $vehicles->count(),
            'present' => 0,
            'absent' => 0,
            'substitute' => 0,
            'no_work_day' => 0,
            'unassigned' => 0,
        ];

        $rows = [];
        foreach ($vehicles as $vehicle) {
            /** @var VehicleDriverAssignment|null $assignment */
            $assignment = $assignments->get($vehicle->id);
            /** @var VehicleDriverAttendance|null $exception */
            $exception = $exceptions->get($vehicle->id);

            $primaryDriver = ($assignment !== null && $assignment->driver !== null)
                ? $assignment->driver
                : (($exception !== null && $exception->primaryDriver !== null)
                    ? $exception->primaryDriver
                    : $vehicle->primaryDriver);
            $hasAssignment = $assignment !== null || $vehicle->primary_driver_party_id !== null;

            if ($exception !== null) {
                if ($exception->status === DriverAttendanceStatus::NoWorkDay) {
                    $displayStatus = 'no_work_day';
                    $displayLabel = 'No Work Day';
                    $summary['no_work_day']++;
                } elseif ($exception->temporary_driver_party_id !== null) {
                    $displayStatus = 'substitute';
                    $displayLabel = 'Absent (Substitute Assigned)';
                    $summary['absent']++;
                    $summary['substitute']++;
                } else {
                    $displayStatus = 'absent';
                    $displayLabel = 'Absent';
                    $summary['absent']++;
                }
            } elseif ($hasAssignment) {
                $displayStatus = 'present';
                $displayLabel = 'Present (Assigned)';
                $summary['present']++;
            } else {
                $displayStatus = 'unassigned';
                $displayLabel = 'Unassigned';
                $summary['unassigned']++;
            }

            $activeTrip = $vehicleTripMap[$vehicle->id] ?? null;

            $rows[] = [
                'vehicle_id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'primary_driver_party_id' => $primaryDriver?->id,
                'primary_driver_name' => $primaryDriver?->name,
                'primary_driver_contact' => $primaryDriver?->contact_number,
                'status' => $displayStatus,
                'status_label' => $displayLabel,
                'attendance_id' => $exception?->id,
                'temporary_driver_party_id' => $exception?->temporary_driver_party_id,
                'temporary_driver_name' => $exception?->temporaryDriver?->name,
                'remarks' => $exception?->remarks,
                'active_trip_lr' => $activeTrip['lr_number'] ?? null,
                'can_mark' => $hasAssignment,
            ];
        }

        return [
            'date' => $targetDate,
            'summary' => $summary,
            'rows' => $rows,
            'busy_driver_ids' => $busyDrivers,
        ];
    }

    /**
     * Mark Absent (optionally with Substitute Driver) or No Work Day for an assigned Own Vehicle.
     *
     * @param  array{
     *     vehicle_id: string,
     *     attendance_date: string,
     *     status: string,
     *     temporary_driver_party_id?: string|null,
     *     remarks?: string|null
     * }  $payload
     */
    public function mark(
        User $actor,
        Company $company,
        ?Branch $branch,
        array $payload,
    ): VehicleDriverAttendance {
        $this->assertCanMutate($actor, $company, $branch);

        $status = DriverAttendanceStatus::from(strtoupper((string) $payload['status']));
        if ($status === DriverAttendanceStatus::Present) {
            throw ValidationException::withMessages([
                'status' => 'Working days are automatic from driver assignment. Only mark absences, substitutes, or no-work days.',
            ]);
        }

        $date = $this->normalizeDate($company, (string) $payload['attendance_date']);

        $vehicle = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereKey((string) $payload['vehicle_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($vehicle === null || $vehicle->ownership !== VehicleOwnership::Own) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Selected Own Vehicle was not found.',
            ]);
        }

        $assignment = VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $vehicle->id)
            ->activePrimaryOn($date)
            ->first();

        $primaryDriverId = $assignment !== null ? $assignment->driver_party_id : $vehicle->primary_driver_party_id;
        if ($primaryDriverId === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'An active primary driver assignment is required for this date before marking attendance.',
            ]);
        }

        $existing = VehicleDriverAttendance::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereDate('attendance_date', $date)
            ->whereNull('deleted_at')
            ->first();

        $temporaryDriverId = null;
        if ($status === DriverAttendanceStatus::Absent && filled($payload['temporary_driver_party_id'] ?? null)) {
            $temporaryDriverId = (string) $payload['temporary_driver_party_id'];
            if ($temporaryDriverId === $primaryDriverId) {
                throw ValidationException::withMessages([
                    'temporary_driver_party_id' => 'Substitute driver cannot be the same as the primary driver.',
                ]);
            }
            $this->assertDriverParty($company, $branch, $temporaryDriverId);
            $this->assertSubstituteAvailable($company, $date, $temporaryDriverId, $existing?->id);
        }

        $remarks = $status === DriverAttendanceStatus::NoWorkDay
            ? (filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null)
            : (filled($payload['remarks'] ?? null) ? trim((string) $payload['remarks']) : null);

        return $this->auditedTransaction->run(function () use (
            $actor,
            $company,
            $branch,
            $vehicle,
            $date,
            $primaryDriverId,
            $status,
            $temporaryDriverId,
            $remarks,
            $existing,
        ): VehicleDriverAttendance {
            return $this->access->withTenant($actor, $company, $branch, function () use (
                $actor,
                $company,
                $branch,
                $vehicle,
                $date,
                $primaryDriverId,
                $status,
                $temporaryDriverId,
                $remarks,
                $existing,
            ): VehicleDriverAttendance {
                if ($existing !== null) {
                    $before = $existing->toArray();
                    $existing->forceFill([
                        'primary_driver_party_id' => $primaryDriverId,
                        'status' => $status->value,
                        'temporary_driver_party_id' => $temporaryDriverId,
                        'remarks' => $remarks,
                        'updated_by_user_id' => $actor->id,
                    ])->save();

                    $this->audit->write(
                        AuditAction::Updated,
                        VehicleDriverAttendance::class,
                        $existing->id,
                        remark: "Updated driver attendance for {$vehicle->registration_number} on {$date}",
                        before: $before,
                        after: $existing->toArray(),
                    );

                    return $existing->fresh(['primaryDriver', 'temporaryDriver']) ?? $existing;
                }

                $attendance = VehicleDriverAttendance::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch instanceof Branch ? $branch->id : $vehicle->branch_id,
                    'vehicle_id' => $vehicle->id,
                    'attendance_date' => $date,
                    'primary_driver_party_id' => $primaryDriverId,
                    'status' => $status->value,
                    'temporary_driver_party_id' => $temporaryDriverId,
                    'remarks' => $remarks,
                    'marked_via' => 'branch_panel',
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    VehicleDriverAttendance::class,
                    $attendance->id,
                    remark: "Marked driver attendance ({$status->value}) for {$vehicle->registration_number} on {$date}",
                    before: null,
                    after: $attendance->toArray(),
                );

                return $attendance->fresh(['primaryDriver', 'temporaryDriver']) ?? $attendance;
            });
        });
    }

    /**
     * Clear an attendance exception (reverts vehicle/driver day to implicit Present).
     */
    public function clear(
        User $actor,
        Company $company,
        ?Branch $branch,
        Vehicle $vehicle,
        string $date,
    ): void {
        $this->assertCanMutate($actor, $company, $branch);
        $targetDate = $this->normalizeDate($company, $date);

        $attendance = VehicleDriverAttendance::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereDate('attendance_date', $targetDate)
            ->whereNull('deleted_at')
            ->first();

        if ($attendance === null) {
            return;
        }

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $vehicle, $targetDate, $attendance): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($actor, $vehicle, $targetDate, $attendance): void {
                $before = $attendance->toArray();
                $attendance->forceFill(['updated_by_user_id' => $actor->id])->save();
                $attendance->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    VehicleDriverAttendance::class,
                    $attendance->id,
                    remark: "Cleared driver attendance exception for {$vehicle->registration_number} on {$targetDate}",
                    before: $before,
                    after: $attendance->toArray(),
                );
            });
        });
    }

    private function assertCanMutate(User $actor, Company $company, ?Branch $branch): void
    {
        $capabilities = app(CompanyCapabilityResolver::class);
        if (! $capabilities->allowsMutate($company, CompanyCapabilities::OWNFLEET_DRIVER_ATTENDANCE)) {
            throw new AuthorizationException($capabilities->denyMessage($company, CompanyCapabilities::OWNFLEET_DRIVER_ATTENDANCE));
        }

        if ($branch !== null) {
            $allowed = app(CompanyAccessService::class)->isCompanyOwner($actor, $company)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch);
            if (! $allowed) {
                throw new AuthorizationException('You are not authorized to record driver attendance.');
            }
        } else {
            $this->access->assertCanMutate($actor, $company, null);
        }
    }

    private function assertDriverParty(Company $company, ?Branch $branch, string $driverPartyId): void
    {
        $builder = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($driverPartyId)
            ->whereNull('deleted_at');

        if ($branch !== null) {
            $builder->visibleInBranch($company->id, $branch->id);
        }

        $driver = $builder->first();
        if ($driver === null) {
            throw ValidationException::withMessages([
                'temporary_driver_party_id' => 'Selected substitute driver was not found in this branch.',
            ]);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $driver->id)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([
                'temporary_driver_party_id' => 'Substitute driver must have an active Driver role.',
            ]);
        }
    }

    private function assertSubstituteAvailable(
        Company $company,
        string $date,
        string $substituteDriverId,
        ?string $exceptAttendanceId = null,
    ): void {
        $otherAttendance = VehicleDriverAttendance::query()
            ->where('company_id', $company->id)
            ->whereDate('attendance_date', $date)
            ->where('temporary_driver_party_id', $substituteDriverId)
            ->whereNull('deleted_at')
            ->when($exceptAttendanceId !== null, fn ($query) => $query->whereKeyNot($exceptAttendanceId))
            ->with('vehicle:id,registration_number')
            ->first();

        if ($otherAttendance !== null) {
            $reg = $otherAttendance->vehicle !== null ? $otherAttendance->vehicle->registration_number : 'another vehicle';

            throw ValidationException::withMessages([
                'temporary_driver_party_id' => "This driver is already assigned as substitute on {$reg} for {$date}.",
            ]);
        }

        $openTrip = Trip::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [TripStatus::Pending->value, TripStatus::InProgress->value])
            ->whereNull('deleted_at')
            ->where(function ($query) use ($substituteDriverId): void {
                $query->where('driver_party_id', $substituteDriverId)
                    ->orWhere('substitute_driver_party_id', $substituteDriverId);
            })
            ->with(['vehicle:id,registration_number', 'lorryReceipt:id,lr_number'])
            ->get()
            ->first(function (Trip $trip) use ($date): bool {
                $start = $trip->departure_at?->toDateString() ?? $trip->created_at?->toDateString();
                $end = $trip->actual_arrival_at?->toDateString();

                return $start !== null && $start <= $date && ($end === null || $end >= $date);
            });

        if ($openTrip !== null) {
            $reg = $openTrip->vehicle !== null ? $openTrip->vehicle->registration_number : 'another vehicle';
            $lr = $openTrip->lorryReceipt?->lr_number;
            $tripLabel = $lr !== null ? "Trip {$lr} ({$reg})" : "an active Trip on {$reg}";

            throw ValidationException::withMessages([
                'temporary_driver_party_id' => "This driver is on {$tripLabel} for {$date}.",
            ]);
        }
    }

    private function normalizeDate(Company $company, ?string $date): string
    {
        if (filled($date)) {
            return CarbonImmutable::parse((string) $date)->toDateString();
        }

        $tz = filled($company->timezone) ? (string) $company->timezone : 'Asia/Kolkata';

        return CarbonImmutable::now($tz)->toDateString();
    }
}
