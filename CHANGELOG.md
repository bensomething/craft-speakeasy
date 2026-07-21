# Release Notes for Sesame

## 1.0.0-beta.1 - 2026-07-21

### Added
- Encrypted **Password** field type (offered on element types with template-rendered URLs) with an optional visibility toggle, masked in template output and kept out of the search index and GraphQL schema, front-end gating with an unlock screen, per-IP/element rate limiting, shared-unlock by password, `no-store` + `noindex` on protected responses, CP-user bypass, and a configurable unlock template.
- Settings split into **General** and **Appearance** tabs.
- **Unlock screen CSS** setting: restyle the bundled unlock screen by overriding its CSS variables (light + dark), seeded with the defaults and sanitised against markup injection. Upgrades to a Monaco editor when `nystudio107/craft-code-editor` is installed, with a plain textarea fallback otherwise, and is disabled with a warning when a custom unlock template is set.
- Warning when a second **Password** field is added to a layout, since only the first gates the element.
