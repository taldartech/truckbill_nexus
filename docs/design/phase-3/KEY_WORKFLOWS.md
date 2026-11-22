# D — Key Workflows

**Status:** Proposed for Product Owner design review

## 1. Create Party with multiple roles

1. Masters → Parties → Create.
2. Choose scope (Shared / This branch).
3. Enter common fields; select ≥1 roles.
4. Role panels expand for role-specific attributes.
5. Submit → server validates → detail page with role chips.
6. Soft-delete requires remark + confirm; no restore control.

## 2. Remove Party role (retain details)

1. Detail → Roles → Remove on a non-last active role.
2. Remark required; role deactivated, attributes retained.
3. Cannot remove last active role (inline error).

## 3. Vehicle create / deactivate

1. Create with registration (normalized uppercase).
2. Duplicate registration → field error.
3. Deactivate via Active toggle or soft-delete with remark.

## 4. Bank account create (masked)

1. Kind = Bank → show bank fields; Cash → name only.
2. After save, list/detail show masked account number only.

## 5. Station + default transporter

1. Owner creates station (shared across owned companies).
2. On station detail for current company/branch, set default transporter party.
3. Helper text: “Changing this default does not alter previously saved documents.”

## 6. Document upload / replace

1. Parent detail → Attachments → Upload (≤2 MB, approved types).
2. Show scan status until available; download disabled until available.
3. Replace: new upload must succeed before version advances; prior version retained in history and storage accounting.
4. Failed scan: toast + no storage count for rejected upload artefact.

## 7. Owner pricing → draft contract

1. Catalogue select modules/add-ons/tenure.
2. Calculator shows ordered discounts and 30% monetary cap note.
3. Save as draft contract → review snapshot (immutable once issued — issued state later).
4. No live payment CTA until PO-P3-PAY.

## 8. Soft-delete confirmation pattern

All soft-deletes: Dialog with remark (required for update/delete audits), consequence text, Cancel / Confirm. No “Restore” button.
