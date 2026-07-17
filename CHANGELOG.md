# Release Notes for Sesame

## Unreleased

### Added
- Initial scaffold: encrypted **Password** field type (offered on element types with template-rendered URLs) with an optional visibility toggle, masked in template output and kept out of the search index and GraphQL schema, front-end gating with an unlock screen, per-IP/element rate limiting, shared-unlock by password, `no-store` + `noindex` on protected responses, CP-user bypass, and a configurable unlock template.
