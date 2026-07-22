# Release Notes for Speakeasy

## 1.0.0-beta.2 - 2026-07-22

### Added
- Live preview beside the **Unlock screen CSS** editor, rendered in a sandboxed iframe from the same markup as the real unlock screen and updating as you type. Includes a **Light**/**Dark** switch and a toggle to preview the error message.
- **Placeholder text**, **Button text**, and **Error text** settings for the bundled unlock screen, each falling back to a translatable default when left blank and reflected live in the preview.
- `--speakeasy-placeholder-text` CSS variable, so the input placeholder colour can be set apart from the input text.

### Changed
- When a custom unlock template is set, the bundled-screen settings are now replaced by a single override notice rather than shown disabled.
- Renamed the unlock screen's CSS variables to spell out `background` and `text` in full and to be role-specific (for example `--speakeasy-bg` is now `--speakeasy-background`, `--speakeasy-fg` is now `--speakeasy-input-text`, and `--speakeasy-error` is now `--speakeasy-error-text`). Custom CSS saved under the old names will need updating.

### Fixed
- `--speakeasy-font` now applies to the unlock screen's input placeholder and button, which as form controls do not inherit `font-family`.
- The unlock screen's error message no longer nudges the centred input upward when it appears.
- Shortened the default font stack to lead with `system-ui`.

## 1.0.0-beta.1 - 2026-07-21

### Added
- Encrypted **Password** field type (offered on element types with template-rendered URLs) with an optional visibility toggle, masked in template output and kept out of the search index and GraphQL schema, front-end gating with an unlock screen, per-IP/element rate limiting, shared-unlock by password, `no-store` + `noindex` on protected responses, CP-user bypass, and a configurable unlock template.
- Settings split into **General** and **Appearance** tabs.
- **Unlock screen CSS** setting: restyle the bundled unlock screen by overriding its CSS variables (light + dark), seeded with the defaults and sanitised against markup injection. Upgrades to a Monaco editor when `nystudio107/craft-code-editor` is installed, with a plain textarea fallback otherwise, and is disabled with a warning when a custom unlock template is set.
- Warning when a second **Password** field is added to a layout, since only the first gates the element.
