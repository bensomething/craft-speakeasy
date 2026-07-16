# Sesame

Per-element password protection for Craft CMS.

Add a **Sesame Password** field to any element type's field layout. Once an
element has a password set, anonymous front-end visitors see an unlock screen
until they enter it. The password is **encrypted at rest** with your project
security key.

## Why Sesame

- **Field-first** — works on any element (entries, categories, assets, …); no
  section config, no template code. Protection = the field has a value.
- **Encrypted, not plaintext** — unlike config/template-based tools, the value
  is encrypted in the database (and revealable in the CP via an eye toggle).
- **Editor-driven** — content editors set a password in the element editor; no
  developer involvement per page.
- **Built to be safe** — constant-time comparison, per-IP/element rate limiting,
  `no-store` + `noindex` on protected responses, shared-unlock when passwords
  match, and a CP-user bypass so live preview keeps working.

## Requirements

Craft CMS 5.10+ and PHP 8.2+.

## Installation

```bash
composer require bensomething/craft-sesame
```

## Usage

1. **Settings → Fields → New field** → field type **Sesame Password**. Add it to
   the field layout(s) of whatever you want to be protectable.
2. Set a password on an element to protect it; clear it to make it public again.

Anonymous visitors get the unlock screen; entering the password reveals the
page. Elements sharing the same password unlock together.

## Settings

| Setting | Default | Purpose |
| --- | --- | --- |
| Bypass for CP users | on | Signed-in users who can view the element skip the gate |
| Max unlock attempts | 5 | Failed tries per IP + element before lockout (0 disables) |
| Lockout window | 300s | Lockout duration / attempt-count expiry |
| Unlock template | *(bundled)* | Override the unlock screen with your own site template |

### Custom unlock template

Point the **Unlock template** setting at a site template. It receives an
`element` variable and must post to the `sesame/unlock` action:

```twig
<form method="post">
    <input type="hidden" name="action" value="sesame/unlock">
    <input type="hidden" name="elementId" value="{{ element.id }}">
    {{ csrfInput() }}
    <input type="password" name="password" autofocus>
    <button type="submit">{{ 'Enter'|t }}</button>
</form>
```

## Note on caching

Protected responses are sent with `no-store`. If you use a server- or CDN-level
full-page cache, make sure it honours that (or excludes protected URLs) so
protected pages aren't served from cache to anonymous visitors.

## License

MIT
