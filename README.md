# Sesame

Per-element password protection for Craft CMS. Add a **Password** field to an element type's field layout, and once a password has been set, anonymous visitors get an unlock screen until they enter it. Passwords are **encrypted at rest** with your project security key.

> **These are shared access passwords, not user credentials.** They're reversibly encrypted so editors can view and share them, deliberately *not* one-way hashed like a login password. Sesame gates a page behind a shared passphrase, it doesn't authenticate individual users. If you need per-user login, use Craft's user accounts.

## Why Sesame

- **Field-first:** protection = the field has a value. No section config or template code.
- **Encrypted, not plaintext:** the value lives encrypted in the database, not in config or templates.
- **Editor-driven:** editors set passwords in the element editor, no per-page developer work.
- **Safe defaults:** constant-time comparison, per-IP/element rate limiting, `no-store` + `noindex` on protected responses, shared unlock when passwords match, and a CP-user bypass so live preview keeps working.

## Requirements

Craft CMS 5.10+ and PHP 8.2+.

## Installation

```bash
composer require bensomething/craft-sesame:^1.0.0-beta.1@beta
```

Sesame is in beta, so the `@beta` flag is needed to install it under a project's default `stable` minimum stability.

## Usage

1. **Settings → Fields → New field**, create a **Password** field, then add it to the field layouts you want to protect.
2. Set a password on an element to protect it. Clear it to make it public again.

Anonymous visitors get the unlock screen. Elements sharing the same password unlock together.

To flag protected elements in your own templates, test the field for a value: set is truthy, empty is `null`:

```twig
{% if entry.<handle> %}🔒{% endif %}
```

This never reveals or decrypts the password, it only checks whether one is set.

## What it protects (and doesn't)

- **Gates the element's own template-rendered URL:** Entries, categories, and custom element types with a template. The field is hidden from the layout designer for assets, users, and global sets, which have no such URL. Placement can't be fully blocked (inline creation, project config), so on a non-gateable element the field warns that a password has no effect.
- **Not static files:** Assets are served without Craft in the request, so the gate never runs. Protecting them is a separate problem, the usual approach is a private filesystem with a controller that authorises and streams each file.
- **Not other queries:** A protected element's fields shown in a listing, relation, eager-loaded loop, GraphQL, or the Element API are not gated, that's up to your templates (see [Note on GraphQL and the API](#note-on-graphql-and-the-api)).
- **Never outputs the password:** `{{ entry.<handle> }}` prints `••••••••`, and the value is kept out of the search index and GraphQL schema. Twig can't unwrap it either, templates only ever get the mask. The plaintext is reachable only from Sesame's own PHP, which the gate uses to compare.
- **Fail-closed on key loss:** If the security key is rotated or lost, existing passwords can't be decrypted and those elements stay locked. Re-enter passwords after a key change.
- **Unlocks live in the visitor's session:** They end when the browser closes, and PHP may expire an idle session sooner (`session.gc_maxlifetime`, often 24 minutes). Unlock duration sets an upper bound on top of that, it can't extend an unlock beyond the session itself, so an unlock lasts for whichever ends first.
- **Rate-limited per IP + element:** Behind a proxy or CDN, make sure Craft is configured to see the real client IP. Rate limiting relies on Craft's cache — a null/dummy cache driver disables the lockout.

## Settings

Settings are split across two tabs. **General:**

| Setting | Default | Purpose |
| --- | --- | --- |
| Bypass for CP users | on | Signed-in users who can view the element skip the gate |
| Unlock duration | 0 | How long an unlock lasts, in seconds (0 = the whole browsing session) |
| Max unlock attempts | 5 | Failed tries per IP + element before lockout (0 disables) |
| Lockout window | 300s | Lockout duration / attempt-count expiry |

**Appearance:**

| Setting | Default | Purpose |
| --- | --- | --- |
| Unlock template | *(bundled)* | Override the unlock screen with your own site template |
| Unlock screen CSS | *(empty)* | Restyle the bundled screen by overriding its CSS variables |

### Restyling the bundled screen

Without replacing the template, you can retheme the bundled unlock screen from the **Unlock screen CSS** field by overriding its CSS variables:

```css
:root {
    --sesame-bg: #101418;
    --sesame-button-bg: #4a7dff;
}
```

Available variables: `--sesame-bg`, `--sesame-fg`, `--sesame-input-bg`, `--sesame-input-border`, `--sesame-input-border-focus`, `--sesame-button-bg`, `--sesame-button-fg`, `--sesame-button-bg-hover`, `--sesame-error`, `--sesame-radius`, `--sesame-font`. This field is ignored once a custom **Unlock template** is set — your template owns its own styling. If the [CKEditor plugin](https://github.com/craftcms/ckeditor) (or anything else depending on `nystudio107/craft-code-editor`) is installed, the field upgrades to a syntax-highlighting Monaco editor; otherwise it's a plain code textarea.

### Custom unlock template

Point the **Unlock template** setting at a site template. It receives an `element` variable and must post to the `sesame/unlock` action:

```twig
<form method="post">
    <input type="hidden" name="action" value="sesame/unlock">
    <input type="hidden" name="elementId" value="{{ element.id }}">
    {{ csrfInput() }}
    <input type="password" name="password" autofocus>
    <button type="submit">{{ 'Enter'|t }}</button>
</form>
```

## Note on GraphQL and the API

The gate only runs when Craft renders an element's URL — it does **not** apply to GraphQL, the Element API, or any decoupled/headless front-end. The password itself is excluded from the schema (it can't be selected), and unlocking is a server-side session flag with no API equivalent. But a protected element's **other** fields stay readable through any API whose scope includes them. Gating API-consumed content is your app's job.

To keep protected content out of an API, prefer scope: leave the section out of your GraphQL token / public schema. If you can't, filter it out — the field handle is exposed as a presence-only query argument (a Craft-wide behaviour), so:

```graphql
# unprotected entries only
{
  entries(section: "home", <handle>: ":empty:") {
    title
  }
}
```

Use `":notempty:"` for only protected entries. It tests presence against the encrypted value, never the plaintext.

## Note on caching

Protected responses are sent with `no-store`. If you use a server- or CDN-level full-page cache, make sure it honours that (or excludes protected URLs) so protected pages aren't served from cache to anonymous visitors.

## Note on light/dark mode

The bundled unlock screen is a self-contained page (Sesame swaps the whole response, so none of your site's CSS or JS loads on it). It adapts to light/dark via the visitor's **OS/browser** preference — `@media (prefers-color-scheme: dark)` — which works everywhere without any cooperation from your templates. It does **not** follow a site's manual theme toggle (a `.dark` class, a `data-theme` attribute, a cookie), because that toggle's JS never runs on the unlock screen. If a visitor's OS is light but they've switched your site to dark, the unlock screen still shows light. To mirror a manual toggle, use a custom **Unlock template** so the screen renders inside your own layout, where your theme logic applies.

## Note on Safari

When editing in Safari, iCloud Keychain may offer to save the field's value as a site password. It keys on the field's label and has no markup-level opt-out — name the field anything other than "Password" (e.g. "Passphrase" or "Access code") to avoid the prompt. Firefox and Chrome are unaffected.

## License

MIT
