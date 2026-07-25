# Release Notes for Speakeasy

## 1.0.0-beta.6 - 2026-07-25

### Changed
- A Password field placed on an entry that's nested inside another element, such as a Matrix block, now warns that it has no effect. A nested entry has no URL of its own for the gate to guard, so the password never applies. The warning is shown only when the entry genuinely has no URL, so a container field that gives its nested entries real pages is left alone.

## 1.0.0-beta.5 - 2026-07-22

### Added
- **Lockdown**, closing every protected element at once. Visitors get a message in place of the unlock screen, no password is accepted, and anyone already unlocked is shut out too. Protected responses return `403` while it's on. Elements without a password are unaffected, and control-panel users still bypass it when **Bypass for control-panel users** is on, so editors and live preview keep working.
- Lockdown is set only by the `SPEAKEASY_LOCKDOWN` environment variable, keeping it per-environment and out of project config, where a stored value would follow a deploy to every other environment. The General tab reports its state as a status label rather than offering a control that couldn't work.
- **Lockdown text** setting, alongside the bundled screen's other copy settings and falling back to a translatable default in the same way.
- A custom **Unlock template** now receives a `lockdown` variable so it can render its own locked state. Templates that ignore it are still safe, as the `speakeasy/unlock` action refuses to run under lockdown.
- Settings can be set in `config/speakeasy.php`, with a commented starter at `src/config.php` to copy. Everything except **Lockdown** is supported.
- Settings named in `config/speakeasy.php` are now shown disabled on the settings screen with Craft's standard override notice, rather than accepting an edit that silently reverts on the next load.

### Changed
- The **Unlock screen CSS** preview's **Show error message** toggle is now a **Message** dropdown, covering the plain screen, the error, and the new lockdown message.
- Renamed `--speakeasy-input-text` to `--speakeasy-text`, now that the lockdown message uses it as well as the input. This is the variable renamed from `--speakeasy-fg` in beta.2, so custom CSS carrying either spelling will need updating.
- A password that can't be decrypted, because the security key changed since it was set, is now called out in the field with a warning explaining that the original is unrecoverable and a new password needs to be entered. Previously the field displayed the raw stored ciphertext as though it were the password.
- A Password field can no longer be added to the same field layout twice. Only the first one gates the element, so a second instance was always inert. Two *different* Password fields can still be placed, and the later ones still warn that they have no effect.

### Fixed
- Saving an element whose password can't be decrypted no longer re-encrypts the stored ciphertext under the new key. That turned the ciphertext into the element's actual password, permanently and with no warning left to show it had happened, and it could be triggered by an edit to an unrelated field. Such a value is now written back exactly as it was found, so it stays gated and stays flagged until an editor replaces it.
- A field rendered in its static (uneditable) state no longer decrypts the password when it's only going to show the mask. The plaintext was never written to the page, but the work was done and the value held in memory for no reason.
- The reveal toggle is hidden while the field is empty, since there's nothing to reveal.

## 1.0.0-beta.3 - 2026-07-22

### Changed
- The General tab's number fields now carry their unit as a suffix beside the input (**seconds**, **attempts**) instead of in the label.

### Fixed
- **Error text** is now scoped to the bundled screen like the other copy settings. A stored value no longer stays live for a custom unlock template, where the field is hidden and so can't be edited.
- The bundled screen's copy settings now treat a whitespace-only value as blank, falling back to the default instead of rendering an invisible placeholder or button label.

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
