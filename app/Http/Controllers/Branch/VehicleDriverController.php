<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Driver\VehicleDriverAssignmentService;
use App\Domains\OwnFleet\Driver\VehicleDriverAttendanceService;
use App\Domains\OwnFleet\Enums\DriverAttendanceStatus;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * OwnFleet Vehicle Drivers register, assignment history, and exception attendance.
 */
class VehicleDriverController extends Controller
{
    use AuthorizesBranchPanel;

    private readonly CompanyCapabilityResolver $capabilities;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly VehicleDriverAssignmentService $assignments,
        private readonly VehicleDriverAttendanceService $attendance,
        ?CompanyCapabilityResolver $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? app(CompanyCapabilityResolver::class);
    }

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertCanView($actor, $company, $branch);

        $q = $request->string('q')->toString();
        $status = $request->string('status')->toString();
        $attendanceDate = $request->string('date')->toString() ?: null;

        $rows = $this->assignments->registerRows(
            company: $company,
            branch: $branch,
            query: $q !== '' ? $q : null,
            status: $status !== '' ? $status : null,
        );

        $attendanceOverview = $this->attendance->dailyOverview($company, $branch, $attendanceDate);

        $driverOptions = app(PartyOptionQuery::class)->forRoles(
            $company,
            $branch,
            PartyRoleType::Driver,
            300,
        );

        $ownVehicleOptions = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->where('ownership', VehicleOwnership::Own->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('registration_number')
            ->get(['id', 'registration_number', 'primary_driver_party_id'])
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'primary_driver_party_id' => $vehicle->primary_driver_party_id,
            ])->values()->all();

        $canMutateDrivers = $this->canMutate($actor, $company, $branch, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS);
        $canMarkAttendance = $this->canMutate($actor, $company, $branch, CompanyCapabilities::OWNFLEET_DRIVER_ATTENDANCE);

        return Inertia::render('branch/masters/vehicle-drivers/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'filters' => [
                'q' => $q,
                'status' => $status,
                'date' => $attendanceOverview['date'],
            ],
            'permissions' => [
                'can_assign' => $canMutateDrivers,
                'can_remove' => $canMutateDrivers,
                'can_mark_attendance' => $canMarkAttendance,
                'can_create_party' => $this->capabilities->allowsMutate($company, CompanyCapabilities::MASTERS_PARTIES)
                    && $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch),
                'can_create_vehicle' => $this->capabilities->allowsMutate($company, CompanyCapabilities::VEHICLE_OWNERSHIP_OWN)
                    && $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch),
            ],
            'rows' => array_map(fn (array $row): array => array_merge($row, [
                'assign_url' => route('branch.vehicle-drivers.assign', [$row['vehicle_id']], absolute: false),
                'remove_url' => route('branch.vehicle-drivers.remove', [$row['vehicle_id']], absolute: false),
                'history_url' => route('branch.vehicle-drivers.history', [$row['vehicle_id']], absolute: false),
                'vehicle_url' => route('branch.vehicles.show', [$row['vehicle_id']], absolute: false),
            ]), $rows),
            'attendance' => [
                'date' => $attendanceOverview['date'],
                'summary' => $attendanceOverview['summary'],
                'busy_driver_ids' => $attendanceOverview['busy_driver_ids'],
                'rows' => array_map(fn (array $row): array => array_merge($row, [
                    'clear_url' => route('branch.vehicle-drivers.attendance.clear', [$row['vehicle_id']], absolute: false),
                ]), $attendanceOverview['rows']),
            ],
            'driverOptions' => $driverOptions,
            'ownVehicleOptions' => $ownVehicleOptions,
            'urls' => [
                'index' => route('branch.vehicle-drivers.index', absolute: false),
                'assign_base' => '/vehicle-drivers',
                'attendance_mark' => route('branch.vehicle-drivers.attendance.mark', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
                'vehicles_index' => route('branch.vehicles.index', absolute: false),
                'dashboard' => route('branch.dashboard', absolute: false),
            ],
        ]);
    }

    public function history(Request $request, Vehicle $vehicle, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertCanView($actor, $company, $branch);
        $this->assertVehicle($company, $branch, $vehicle);

        $history = $this->assignments->historyForVehicle($company, $vehicle);
        $asOf = $this->companyToday($company);

        $driverOptions = app(PartyOptionQuery::class)->forRoles(
            $company,
            $branch,
            PartyRoleType::Driver,
            300,
        );

        $canMutateDrivers = $this->canMutate($actor, $company, $branch, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS);

        return Inertia::render('branch/masters/vehicle-drivers/history', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'vehicle' => [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'ownership' => $vehicle->ownership?->value,
                'is_active' => (bool) $vehicle->is_active,
                'primary_driver_party_id' => $vehicle->primary_driver_party_id,
                'primary_driver_name' => $vehicle->primaryDriver?->name,
            ],
            'permissions' => [
                'can_assign' => $canMutateDrivers,
                'can_remove' => $canMutateDrivers,
                'can_create_party' => $this->capabilities->allowsMutate($company, CompanyCapabilities::MASTERS_PARTIES)
                    && $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch),
            ],
            'assignments' => $history->map(function (VehicleDriverAssignment $row) use ($asOf): array {
                $role = $row->driver?->roles
                    ->first(fn (PartyRole $partyRole): bool => $partyRole->role === PartyRoleType::Driver->value && $partyRole->deactivated_at === null);
                $attrs = is_array($role?->role_attributes) ? $role->role_attributes : [];

                return [
                    'id' => $row->id,
                    'driver_party_id' => $row->driver_party_id,
                    'driver_name' => $row->driver !== null ? $row->driver->name : '—',
                    'driver_contact_number' => $row->driver?->contact_number,
                    'driver_license_number' => isset($attrs['license_number']) ? (string) $attrs['license_number'] : null,
                    'driver_license_expiry' => isset($attrs['license_expiry']) ? (string) $attrs['license_expiry'] : null,
                    'assigned_from' => $row->assigned_from->toDateString(),
                    'assigned_to' => $row->assigned_to?->toDateString(),
                    'status' => $row->statusOn($asOf),
                    'remarks' => $row->remarks,
                ];
            })->values()->all(),
            'driverOptions' => $driverOptions,
            'urls' => [
                'index' => route('branch.vehicle-drivers.index', absolute: false),
                'vehicle' => route('branch.vehicles.show', [$vehicle], absolute: false),
                'assign' => route('branch.vehicle-drivers.assign', [$vehicle], absolute: false),
                'remove' => route('branch.vehicle-drivers.remove', [$vehicle], absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
            ],
        ]);
    }

    public function assign(Request $request, Vehicle $vehicle, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);

        $validated = $request->validate([
            'driver_party_id' => ['required', 'uuid'],
            'assigned_from' => ['required', 'date'],
            'assigned_to' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assignments->assignPrimaryDriver(
            actor: $actor,
            company: $company,
            branch: $branch,
            vehicle: $vehicle,
            driverPartyId: (string) $validated['driver_party_id'],
            assignedFrom: (string) $validated['assigned_from'],
            assignedTo: $validated['assigned_to'] ?? null,
            remarks: $validated['remarks'] ?? null,
        );

        return back()->with('success', 'Primary Driver assignment saved.');
    }

    public function remove(Request $request, Vehicle $vehicle, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);

        $validated = $request->validate([
            'effective_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assignments->removePrimaryDriver(
            actor: $actor,
            company: $company,
            branch: $branch,
            vehicle: $vehicle,
            effectiveDate: $validated['effective_date'] ?? null,
            remarks: $validated['remarks'] ?? null,
        );

        return back()->with('success', 'Primary Driver removed. Assignment history preserved.');
    }

    public function markAttendance(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', Rule::enum(DriverAttendanceStatus::class)],
            'temporary_driver_party_id' => ['nullable', 'uuid'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->attendance->mark($actor, $company, $branch, $validated);

        return back()->with('success', 'Driver attendance updated.');
    }

    public function clearAttendance(Request $request, Vehicle $vehicle, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);

        $validated = $request->validate([
            'attendance_date' => ['required', 'date'],
        ]);

        $this->attendance->clear($actor, $company, $branch, $vehicle, (string) $validated['attendance_date']);

        return back()->with('success', 'Attendance exception cleared (Present by assignment).');
    }

    private function assertCanView(User $actor, Company $company, Branch $branch): void
    {
        $allowed = $this->access->isCompanyOwner($actor, $company)
            || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_VIEW, $company, $branch)
            || $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_VIEW, $company, $branch);

        if (! $allowed) {
            abort(403, 'Not authorized to view vehicle drivers.');
        }
    }

    private function canMutate(User $actor, Company $company, Branch $branch, string $capability): bool
    {
        return $this->capabilities->allowsMutate($company, $capability)
            && ($this->access->isCompanyOwner($actor, $company)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch));
    }

    private function assertVehicle(Company $company, Branch $branch, Vehicle $vehicle): void
    {
        if ($vehicle->company_id !== $company->id || $vehicle->ownership !== VehicleOwnership::Own) {
            abort(404);
        }

        try {
            app(MasterAccess::class)->assertRecordVisibleInBranch($company, $branch, Vehicle::class, $vehicle->id);
        } catch (MasterDomainException) {
            abort(404);
        }
    }

    private function companyToday(Company $company): string
    {
        $tz = filled($company->timezone) ? (string) $company->timezone : 'Asia/Kolkata';

        return now($tz)->toDateString();
    }
}
