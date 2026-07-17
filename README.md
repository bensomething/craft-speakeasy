# Sesame

Per-element password protection for Craft CMS.

Add a **Sesame Password** field to any element type's field layout. Once an element has a password set, anonymous front-end visitors see an unlock screen until they enter it. The password is **encrypted at rest** with your project security key.

## Why Sesame

- **Field-first:** add it to any element's field layout — no section config, no template code. Protection = the field has a value. (Gating needs a template-rendered URL; see [What Sesame protects](#what-sesame-protects).)
- **Encrypted, not plaintext:** unlike config/template-based tools, the value is encrypted in the database (revealable in the CP via an eye toggle, or shown as plain text via a field setting).
- **Editor-driven:** content editors set a password in the element editor; no developer involvement per page.
- **Built to be safe:** constant-time comparison, per-IP/element rate limiting, `no-store` + `noindex` on protected responses, shared-unlock when passwords match, and a CP-user bypass so live preview keeps working.

## Requirements

Craft CMS 5.10+ and PHP 8.2+.

## Installation

```bash
composer require bensomething/craft-sesame
```

## Usage

1. **Settings → Fields → New field** → field type **Password**. Add it to the field layout(s) of whatever you want to be protectable.
2. Set a password on an element to protect it; clear it to make it public again.

Anonymous visitors get the unlock screen; entering the password reveals the page. Elements sharing the same password unlock together.

## What Sesame protects

Sesame gates an element's **own URL**, and only when Craft renders that URL through a template — entries, categories, and custom element types with a template. It **cannot** protect assets or anything served as a static file: those URLs are delivered straight from your web server or filesystem without Craft in the request, so the gate never runs. The field is therefore only offered on element types with template-rendered URLs — you won't see it in the field layout designer for assets, users, or global sets. For real asset protection, use a private volume served through a controller.

It also does **not** filter the element out of other queries. If you output a protected element's fields somewhere else (a listing, a relation, an eager-loaded loop, the Element API), that content is not gated; protecting those surfaces is up to your templates.

Outputting the field renders a fixed mask, not the password: `{{ entry.<handle> }}` prints `••••••••`, and the value is kept out of the search index and the GraphQL schema. The real password has to remain recoverable in code (that's how the gate compares it), so it's still available if you deliberately ask for it — just don't build a template that reveals it.

Passwords are encrypted at rest with your project's security key. If that key is rotated or lost, existing passwords can no longer be decrypted: the affected elements stay locked (fail-closed) and the original values are unrecoverable, so re-enter passwords after a key change.

Unlock attempts are rate-limited per client IP + element. Behind a proxy or CDN, make sure Craft is configured to see the real client IP, or the limit applies to the proxy's address.

## Settings

| Setting | Default | Purpose |
| --- | --- | --- |
| Bypass for CP users | on | Signed-in users who can view the element skip the gate |
| Max unlock attempts | 5 | Failed tries per IP + element before lockout (0 disables) |
| Lockout window | 300s | Lockout duration / attempt-count expiry |
| Unlock template | *(bundled)* | Override the unlock screen with your own site template |

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

## Note on Safari

When editing an element in Safari, iCloud Keychain may offer to save the field's value as a site password on save. It keys on the field's label text, and there's no markup-level opt-out. Name the field anything other than "Password" (e.g. "Passphrase" or "Access code") to avoid the prompt. Firefox and Chrome are unaffected.

## Note on caching

Protected responses are sent with `no-store`. If you use a server- or CDN-level full-page cache, make sure it honours that (or excludes protected URLs) so protected pages aren't served from cache to anonymous visitors.

## License

MIT
