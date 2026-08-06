# Release Notes for Speakeasy

## Unreleased

### Added
- Protected elements carry a padlock wherever the control panel lists them: element indexes, relation fields, element selects and cards. Checking for a password never decrypts it, so the icon costs nothing to draw.
- **Show element lock icon** setting, on by default, for turning that padlock off.

### Changed
- The unlock form no longer posts back the stored value of a password that can't be decrypted. A crafted post could otherwise put a chosen password into the database unencrypted, with the field still showing as empty. Exploiting it needed permission to edit the element.
- Failed unlock attempts are counted per password rather than per element, so elements sharing a password now share one budget of guesses instead of each adding another.
- Unlocking issues a new session id, so an id planted beforehand can't be replayed to get past the gate.
- The unlock action no longer answers for drafts, revisions or disabled elements, which keep the canonical element's URI but have no public page. A user who can already view the element in the control panel is still let through, so previewing a protected draft keeps working with **Bypass for control-panel users** off.
- The attempt counter's cache key is keyed with the security key rather than hashed, keeping both the password and the visitor's IP out of a leaked cache.
- A password guess of the wrong length is no longer measurably quicker to reject than one of the right length.

### Fixed
- **Lockout window** can no longer be set to 0, which the cache read as "never expire" and left a visitor who reached the limit locked out until it was flushed by hand. Turn rate limiting off with **Failed attempts** instead. A stored 0 needs changing before the settings screen will save, and one in `config/speakeasy.php` is treated as one second.
- When a layout holds two Password fields, the one gating the element now says so, instead of leaving the editor to work it out. The "no effect" warning is shown only on a field that holds a password another field is overriding, since the gate falls through an empty field to a later one.
- Settings define their validation rules through `defineRules()`, restoring the `EVENT_DEFINE_RULES` extension point.
- Validation errors name the setting as the settings screen labels it, rather than Yii's guess at a name from the attribute ("Attempt Window Seconds").

## 1.0.0-beta.6 - 2026-07-25

### Changed
- A Password field on an entry nested inside another element, such as a Matrix block, now warns that it has no effect, since a nested entry has no URL of its own to guard. A container field that gives its nested entries real pages is left alone.

## 1.0.0-beta.5 - 2026-07-22

### Added
- **Lockdown**, closing every protected element at once. No password is accepted, anyone already unlocked is shut out, and protected responses return `403`. Elements without a password are unaffected, and control-panel users still bypass it when **Bypass for control-panel users** is on.
- Lockdown is set only by the `SPEAKEASY_LOCKDOWN` environment variable, keeping it per-environment and out of project config. The General tab reports its state as a status label.
- **Lockdown text** setting, falling back to a translatable default like the other copy settings.
- A custom **Unlock template** now receives a `lockdown` variable so it can render its own locked state. Templates that ignore it are still safe.
- Settings can be set in `config/speakeasy.php`, with a commented starter at `src/config.php` to copy. Everything except **Lockdown** is supported.
- Settings named in `config/speakeasy.php` are shown disabled with Craft's standard override notice, rather than accepting an edit that silently reverts.

### Changed
- The **Unlock screen CSS** preview's **Show error message** toggle is now a **Message** dropdown, covering the plain screen, the error, and the lockdown message.
- Renamed `--speakeasy-input-text` to `--speakeasy-text`, now the lockdown message uses it too. This is the variable renamed from `--speakeasy-fg` in beta.2, so custom CSS carrying either spelling will need updating.
- A password that can't be decrypted, because the security key changed since it was set, is now called out in the field with a warning that the original is unrecoverable. Previously the field displayed the raw ciphertext as though it were the password.
- A Password field can no longer be added to the same field layout twice, since only the first gates the element. Two *different* Password fields can still be placed, and the later ones still warn that they have no effect.

### Fixed
- Saving an element whose password can't be decrypted no longer re-encrypts the ciphertext under the new key, which turned it into the element's actual password and could be triggered by an edit to an unrelated field. It's now written back exactly as found, so it stays gated and stays flagged until replaced.
- A field rendered in its static (uneditable) state no longer decrypts the password when it's only going to show the mask.
- The reveal toggle is hidden while the field is empty.

## 1.0.0-beta.3 - 2026-07-22

### Changed
- The General tab's number fields carry their unit as a suffix beside the input (**seconds**, **attempts**) instead of in the label.

### Fixed
- **Error text** is scoped to the bundled screen like the other copy settings, so a stored value no longer stays live for a custom unlock template, where the field is hidden and can't be edited.
- The bundled screen's copy settings treat a whitespace-only value as blank, falling back to the default instead of rendering an invisible placeholder or button label.

## 1.0.0-beta.2 - 2026-07-22

### Added
- Live preview beside the **Unlock screen CSS** editor, rendered in a sandboxed iframe from the same markup as the real unlock screen and updating as you type. Includes a **Light**/**Dark** switch and a toggle to preview the error message.
- **Placeholder text**, **Button text**, and **Error text** settings for the bundled unlock screen, each falling back to a translatable default when left blank and reflected live in the preview.
- `--speakeasy-placeholder-text` CSS variable, so the input placeholder colour can be set apart from the input text.

### Changed
- When a custom unlock template is set, the bundled-screen settings are replaced by a single override notice rather than shown disabled.
- Renamed the unlock screen's CSS variables to spell out `background` and `text` in full and to be role-specific (`--speakeasy-bg` to `--speakeasy-background`, `--speakeasy-fg` to `--speakeasy-input-text`, `--speakeasy-error` to `--speakeasy-error-text`). Custom CSS saved under the old names will need updating.

### Fixed
- `--speakeasy-font` now applies to the unlock screen's input placeholder and button, which as form controls do not inherit `font-family`.
- The unlock screen's error message no longer nudges the centred input upward when it appears.
- Shortened the default font stack to lead with `system-ui`.

## 1.0.0-beta.1 - 2026-07-21

### Added
- Encrypted **Password** field type, offered on element types with template-rendered URLs. Masked in template output, kept out of the search index and GraphQL schema, with an optional visibility toggle.
- Front-end gating with an unlock screen, rate limiting per IP and element, shared unlock by password, `no-store` and `noindex` on protected responses, control-panel-user bypass, and a configurable unlock template.
- Settings split into **General** and **Appearance** tabs.
- **Unlock screen CSS** setting: restyle the bundled unlock screen by overriding its CSS variables (light and dark), seeded with the defaults and sanitised against markup injection. Upgrades to a Monaco editor when `nystudio107/craft-code-editor` is installed, with a plain textarea fallback otherwise, and is disabled with a warning when a custom unlock template is set.
- Warning when a second **Password** field is added to a layout, since only the first gates the element.
