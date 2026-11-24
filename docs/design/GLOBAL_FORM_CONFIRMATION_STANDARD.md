# Global Form Confirmation Standard

**Components:** `resources/js/components/form-confirmation/*`

## Modes

- **Create** — field summary review before submit
- **Edit** — previous/new change table with Modified/Added/Removed text indicators
- **Destructive** — danger confirm (framework ready)

## Rules

Keyboard/form submit must open confirmation (not button-only). Cancel preserves form state. Confirmation is UX only — server authorization remains mandatory.
