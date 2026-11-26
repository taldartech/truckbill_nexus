import type { ReactNode } from 'react';
import {
    AppFormCard,
    AppFormGrid,
    AppFormSection,
} from '@/components/app-form-page';
import { StateCityFields } from '@/components/geography/state-city-fields';
import InputError from '@/components/input-error';
import { partyRoleDisplayLabel } from '@/components/masters/party-master/party-role-labels';
import { derivePartyFieldModel } from '@/components/masters/party-master/party-role-field-model';
import type {
    DriverAttrs,
    PartyBasicValues,
    TransporterAttrs,
} from '@/components/masters/party-master/party-master-types';
import { TransporterTdsPanel } from '@/components/masters/party-master/transporter-tds-panel';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type PartyMasterFormFieldsProps = {
    idPrefix?: string;
    variant?: 'page' | 'sheet';
    nativeForm?: boolean;
    errors: Record<string, string>;
    roleOptions: { value: string; label: string }[];
    selectedRoles: string[];
    onToggleRole?: (role: string) => void;
    rolesLocked?: boolean;
    basic: PartyBasicValues;
    onBasicChange: (patch: Partial<PartyBasicValues>) => void;
    stateId: string;
    cityId: string;
    state: string;
    city: string;
    onGeographyChange: (next: {
        state_id: string;
        city_id: string;
        state: string;
        city: string;
    }) => void;
    canQuickCreateGeography: boolean;
    roleIndex: Record<string, number>;
    pan: string;
    setPan: (value: string) => void;
    transporter: TransporterAttrs;
    setTransporter: (
        updater: (current: TransporterAttrs) => TransporterAttrs,
    ) => void;
    driver: DriverAttrs;
    setDriver: (updater: (current: DriverAttrs) => DriverAttrs) => void;
    tdsPreviewUrl?: string;
    /** Existing party GST flag for edit mode display only. */
    partyGstApplicable?: boolean | null;
};

function roleHiddenInput(
    nativeForm: boolean,
    selectedRoles: string[],
    roleIndex: Record<string, number>,
    role: string,
) {
    if (!nativeForm || !selectedRoles.includes(role)) {
        return null;
    }

    return (
        <input
            type="hidden"
            name={`roles[${roleIndex[role]}][role]`}
            value={role}
        />
    );
}

export function PartyMasterFormFields({
    idPrefix = '',
    variant = 'page',
    nativeForm = true,
    errors,
    roleOptions,
    selectedRoles,
    onToggleRole,
    rolesLocked = false,
    basic,
    onBasicChange,
    stateId,
    cityId,
    state,
    city,
    onGeographyChange,
    canQuickCreateGeography,
    roleIndex,
    pan,
    setPan,
    transporter,
    setTransporter,
    driver,
    setDriver,
    tdsPreviewUrl,
    partyGstApplicable,
}: PartyMasterFormFieldsProps) {
    const model = derivePartyFieldModel(selectedRoles);
    const sectionWrapper =
        variant === 'page'
            ? (title: string, description: string, children: ReactNode) => (
                  <AppFormCard>
                      <AppFormSection title={title} description={description}>
                          {children}
                      </AppFormSection>
                  </AppFormCard>
              )
            : (title: string, description: string, children: ReactNode) => (
                  <div className="space-y-3 rounded-md border border-border p-3">
                      <div className="space-y-1">
                          <h3 className="text-sm font-semibold">{title}</h3>
                          <p className="text-sm text-muted-foreground">
                              {description}
                          </p>
                      </div>
                      {children}
                  </div>
              );

    const gstSelectValue =
        basic.is_gst_applicable !== ''
            ? basic.is_gst_applicable
            : partyGstApplicable === false
              ? '0'
              : partyGstApplicable === true
                ? '1'
                : '';

    return (
        <div className="space-y-6">
            {sectionWrapper(
                'Roles',
                rolesLocked
                    ? 'Role is fixed for this quick add.'
                    : 'Select every capacity this party serves. Panels below unlock for the selected roles.',
                <>
                    <InputError message={errors.roles} />
                    <InputError
                        message={
                            errors['roles.attributes'] as string | undefined
                        }
                    />
                    {rolesLocked ? (
                        <div className="flex flex-wrap gap-2">
                            {selectedRoles.map((role) => (
                                <span
                                    key={role}
                                    className="rounded-lg border border-primary bg-accent px-3 py-2 text-sm font-medium text-accent-foreground"
                                >
                                    {partyRoleDisplayLabel(
                                        role,
                                        roleOptions.find(
                                            (o) => o.value === role,
                                        )?.label ?? role,
                                    )}
                                </span>
                            ))}
                            {selectedRoles.map((role) =>
                                roleHiddenInput(
                                    nativeForm,
                                    selectedRoles,
                                    roleIndex,
                                    role,
                                ),
                            )}
                        </div>
                    ) : (
                        <div
                            className="flex w-full min-w-0 flex-wrap gap-2"
                            role="group"
                            aria-label="Party roles"
                        >
                            {roleOptions.map((option) => {
                                const checked = selectedRoles.includes(
                                    option.value,
                                );

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        role="checkbox"
                                        aria-checked={checked}
                                        onClick={() =>
                                            onToggleRole?.(option.value)
                                        }
                                        className={cn(
                                            'min-w-[9rem] flex-1 rounded-lg border px-3 py-2.5 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:max-w-none sm:flex-[1_1_10rem]',
                                            checked
                                                ? 'border-primary bg-accent text-accent-foreground shadow-xs'
                                                : 'border-border bg-background hover:border-primary/40',
                                        )}
                                    >
                                        <span className="font-medium">
                                            {partyRoleDisplayLabel(
                                                option.value,
                                                option.label,
                                            )}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {!rolesLocked
                        ? selectedRoles.map((role) =>
                              roleHiddenInput(
                                  nativeForm,
                                  selectedRoles,
                                  roleIndex,
                                  role,
                              ),
                          )
                        : null}
                </>,
            )}

            {sectionWrapper(
                'Basic information',
                model.driverOnly
                    ? 'Name and mobile used for dispatch.'
                    : 'Legal or trading identity and reachable contact.',
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor={`${idPrefix}name`} required>
                            Name
                        </Label>
                        <Input
                            id={`${idPrefix}name`}
                            name={nativeForm ? 'name' : undefined}
                            required
                            value={basic.name}
                            onChange={(event) =>
                                onBasicChange({ name: event.target.value })
                            }
                        />
                        <InputError message={errors.name} />
                    </div>
                    <AppFormGrid cols={3}>
                        {model.showBusinessContact ? (
                            <div className="space-y-2">
                                <Label
                                    htmlFor={`${idPrefix}contact_person`}
                                    required={model.contactPersonRequired}
                                >
                                    Contact person
                                </Label>
                                <Input
                                    id={`${idPrefix}contact_person`}
                                    name={
                                        nativeForm
                                            ? 'contact_person'
                                            : undefined
                                    }
                                    required={model.contactPersonRequired}
                                    value={basic.contact_person}
                                    onChange={(event) =>
                                        onBasicChange({
                                            contact_person: event.target.value,
                                        })
                                    }
                                />
                                <InputError message={errors.contact_person} />
                            </div>
                        ) : null}
                        <div className="space-y-2">
                            <Label
                                htmlFor={`${idPrefix}contact_number`}
                                required={model.contactNumberRequired}
                            >
                                {model.driverOnly
                                    ? 'Mobile number'
                                    : 'Contact number'}
                            </Label>
                            <Input
                                id={`${idPrefix}contact_number`}
                                name={nativeForm ? 'contact_number' : undefined}
                                required={model.contactNumberRequired}
                                value={basic.contact_number}
                                onChange={(event) =>
                                    onBasicChange({
                                        contact_number: event.target.value,
                                    })
                                }
                            />
                            <InputError message={errors.contact_number} />
                        </div>
                        {model.showBusinessContact ? (
                            <div className="space-y-2">
                                <Label htmlFor={`${idPrefix}email`}>
                                    Email
                                </Label>
                                <Input
                                    id={`${idPrefix}email`}
                                    name={nativeForm ? 'email' : undefined}
                                    type="email"
                                    value={basic.email}
                                    onChange={(event) =>
                                        onBasicChange({
                                            email: event.target.value,
                                        })
                                    }
                                />
                                <InputError message={errors.email} />
                            </div>
                        ) : null}
                    </AppFormGrid>
                </div>,
            )}

            {sectionWrapper(
                'Address',
                'Location used on LR and invoice documents.',
                <AppFormGrid cols={2}>
                    <div className="sm:col-span-2">
                        <StateCityFields
                            canQuickCreate={canQuickCreateGeography}
                            stateId={stateId}
                            cityId={cityId}
                            stateName={state}
                            cityName={city}
                            required={model.stateCityRequired}
                            stateError={errors.state_id ?? errors.state}
                            cityError={errors.city_id ?? errors.city}
                            onChange={onGeographyChange}
                        />
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor={`${idPrefix}address`}>Address</Label>
                        <Input
                            id={`${idPrefix}address`}
                            name={nativeForm ? 'address' : undefined}
                            value={basic.address}
                            onChange={(event) =>
                                onBasicChange({ address: event.target.value })
                            }
                        />
                    </div>
                </AppFormGrid>,
            )}

            {model.needsDriver
                ? sectionWrapper(
                      'Driver details',
                      'Shown when the driver role is selected.',
                      <AppFormGrid cols={2}>
                          <div className="space-y-2">
                              <Label
                                  htmlFor={`${idPrefix}driver_type`}
                                  required
                              >
                                  Driver type
                              </Label>
                              <select
                                  id={`${idPrefix}driver_type`}
                                  className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                  value={driver.driver_type}
                                  onChange={(event) =>
                                      setDriver((current) => ({
                                          ...current,
                                          driver_type:
                                              event.target.value === 'market'
                                                  ? 'market'
                                                  : 'permanent',
                                      }))
                                  }
                              >
                                  <option value="permanent">Permanent</option>
                                  <option value="market">Market</option>
                              </select>
                              {nativeForm ? (
                                  <input
                                      type="hidden"
                                      name={`roles[${roleIndex.driver}][attributes][driver_type]`}
                                      value={driver.driver_type}
                                  />
                              ) : null}
                              <InputError
                                  message={
                                      errors['roles.attributes.driver_type']
                                  }
                              />
                          </div>
                          <div className="space-y-2">
                              <Label
                                  htmlFor={`${idPrefix}license_number`}
                                  required={driver.driver_type === 'permanent'}
                              >
                                  License number
                              </Label>
                              <Input
                                  id={`${idPrefix}license_number`}
                                  required={driver.driver_type === 'permanent'}
                                  value={driver.license_number}
                                  onChange={(event) =>
                                      setDriver((current) => ({
                                          ...current,
                                          license_number: event.target.value,
                                      }))
                                  }
                              />
                              {nativeForm && driver.license_number !== '' ? (
                                  <input
                                      type="hidden"
                                      name={`roles[${roleIndex.driver}][attributes][license_number]`}
                                      value={driver.license_number}
                                  />
                              ) : null}
                              <InputError
                                  message={
                                      errors['roles.attributes.license_number']
                                  }
                              />
                          </div>
                          <div className="space-y-2">
                              <Label htmlFor={`${idPrefix}license_expiry`}>
                                  License expiry
                              </Label>
                              <Input
                                  id={`${idPrefix}license_expiry`}
                                  type="date"
                                  value={driver.license_expiry}
                                  onChange={(event) =>
                                      setDriver((current) => ({
                                          ...current,
                                          license_expiry: event.target.value,
                                      }))
                                  }
                              />
                              {nativeForm && driver.license_expiry !== '' ? (
                                  <input
                                      type="hidden"
                                      name={`roles[${roleIndex.driver}][attributes][license_expiry]`}
                                      value={driver.license_expiry}
                                  />
                              ) : null}
                          </div>
                          {driver.driver_type === 'permanent' ? (
                              <>
                                  {(
                                      [
                                          [
                                              'joining_date',
                                              'Joining date',
                                              'date',
                                          ],
                                          ['aadhaar', 'Aadhaar', 'text'],
                                          ['pan', 'Driver PAN', 'text'],
                                          [
                                              'emergency_contact_name',
                                              'Emergency contact name',
                                              'text',
                                          ],
                                          [
                                              'emergency_contact_mobile',
                                              'Emergency contact mobile',
                                              'text',
                                          ],
                                          [
                                              'monthly_payment_base',
                                              'Monthly payment base',
                                              'text',
                                          ],
                                          ['bank_name', 'Bank name', 'text'],
                                          [
                                              'bank_account_number',
                                              'Bank account number',
                                              'text',
                                          ],
                                          ['ifsc', 'IFSC', 'text'],
                                      ] as const
                                  ).map(([key, label, type]) => (
                                      <div key={key} className="space-y-2">
                                          <Label
                                              htmlFor={`${idPrefix}driver_${key}`}
                                          >
                                              {label}
                                          </Label>
                                          <Input
                                              id={`${idPrefix}driver_${key}`}
                                              type={type}
                                              value={driver[key]}
                                              onChange={(event) =>
                                                  setDriver((current) => ({
                                                      ...current,
                                                      [key]:
                                                          key === 'pan'
                                                              ? event.target.value.toUpperCase()
                                                              : event.target
                                                                    .value,
                                                  }))
                                              }
                                              className={
                                                  key === 'pan'
                                                      ? 'uppercase'
                                                      : undefined
                                              }
                                          />
                                          {nativeForm && driver[key] !== '' ? (
                                              <input
                                                  type="hidden"
                                                  name={`roles[${roleIndex.driver}][attributes][${key}]`}
                                                  value={driver[key]}
                                              />
                                          ) : null}
                                      </div>
                                  ))}
                              </>
                          ) : null}
                      </AppFormGrid>,
                  )
                : null}

            {model.showGstBusinessTax
                ? sectionWrapper(
                      'Business & tax',
                      'GST registration for customers, consignors, consignees and vendors.',
                      <AppFormGrid cols={3}>
                          {model.showPartyPanField ? (
                              <div className="space-y-2">
                                  <Label htmlFor={`${idPrefix}pan`}>PAN</Label>
                                  <Input
                                      id={`${idPrefix}pan`}
                                      name={nativeForm ? 'pan' : undefined}
                                      value={basic.pan}
                                      onChange={(event) =>
                                          onBasicChange({
                                              pan: event.target.value.toUpperCase(),
                                          })
                                      }
                                      className="uppercase"
                                      maxLength={10}
                                      autoComplete="off"
                                  />
                                  <InputError message={errors.pan} />
                              </div>
                          ) : null}
                          <div className="space-y-2">
                              <Label htmlFor={`${idPrefix}gstin`}>GSTIN</Label>
                              <Input
                                  id={`${idPrefix}gstin`}
                                  name={nativeForm ? 'gstin' : undefined}
                                  value={basic.gstin}
                                  onChange={(event) =>
                                      onBasicChange({
                                          gstin: event.target.value.toUpperCase(),
                                      })
                                  }
                                  className="uppercase"
                              />
                              <InputError message={errors.gstin} />
                          </div>
                          <div className="space-y-2">
                              <Label htmlFor={`${idPrefix}is_gst_applicable`}>
                                  GST applicable
                              </Label>
                              <select
                                  id={`${idPrefix}is_gst_applicable`}
                                  name={
                                      nativeForm
                                          ? 'is_gst_applicable'
                                          : undefined
                                  }
                                  className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                  value={gstSelectValue}
                                  onChange={(event) =>
                                      onBasicChange({
                                          is_gst_applicable: event.target
                                              .value as PartyBasicValues['is_gst_applicable'],
                                      })
                                  }
                              >
                                  <option value="">Not specified</option>
                                  <option value="1">Yes</option>
                                  <option value="0">No</option>
                              </select>
                          </div>
                      </AppFormGrid>,
                  )
                : null}

            {model.needsTransporter ? (
                <AppFormCard>
                    <TransporterTdsPanel
                        roleIndex={roleIndex.transporter}
                        pan={pan}
                        setPan={setPan}
                        transporter={transporter}
                        setTransporter={setTransporter}
                        errors={errors}
                        previewUrl={tdsPreviewUrl}
                        idPrefix={idPrefix}
                        nativeForm={nativeForm}
                        variant={variant}
                    />
                    <AppFormSection
                        title="Transporter settlement"
                        description="Payout identifiers for broker hire settlement."
                    >
                        <AppFormGrid cols={2}>
                            <div className="space-y-2">
                                <Label htmlFor={`${idPrefix}short_name`}>
                                    Short name
                                </Label>
                                <Input
                                    id={`${idPrefix}short_name`}
                                    value={transporter.short_name}
                                    onChange={(event) =>
                                        setTransporter((current) => ({
                                            ...current,
                                            short_name: event.target.value,
                                        }))
                                    }
                                />
                                {nativeForm && transporter.short_name !== '' ? (
                                    <input
                                        type="hidden"
                                        name={`roles[${roleIndex.transporter}][attributes][short_name]`}
                                        value={transporter.short_name}
                                    />
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor={`${idPrefix}beneficiary_id`}>
                                    Beneficiary ID
                                </Label>
                                <Input
                                    id={`${idPrefix}beneficiary_id`}
                                    value={transporter.beneficiary_id}
                                    onChange={(event) =>
                                        setTransporter((current) => ({
                                            ...current,
                                            beneficiary_id: event.target.value,
                                        }))
                                    }
                                />
                                {nativeForm &&
                                transporter.beneficiary_id !== '' ? (
                                    <input
                                        type="hidden"
                                        name={`roles[${roleIndex.transporter}][attributes][beneficiary_id]`}
                                        value={transporter.beneficiary_id}
                                    />
                                ) : null}
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor={`${idPrefix}bank_account`}>
                                    Bank account
                                </Label>
                                <Input
                                    id={`${idPrefix}bank_account`}
                                    value={transporter.bank_account}
                                    onChange={(event) =>
                                        setTransporter((current) => ({
                                            ...current,
                                            bank_account: event.target.value,
                                        }))
                                    }
                                />
                                {nativeForm &&
                                transporter.bank_account !== '' ? (
                                    <input
                                        type="hidden"
                                        name={`roles[${roleIndex.transporter}][attributes][bank_account]`}
                                        value={transporter.bank_account}
                                    />
                                ) : null}
                            </div>
                        </AppFormGrid>
                    </AppFormSection>
                </AppFormCard>
            ) : null}
        </div>
    );
}
