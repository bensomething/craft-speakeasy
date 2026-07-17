# Sesame

Per-element password protection for Craft CMS. Add a **Password** field to an element type's field layout, and once a password has been set, anonymous visitors get an unlock screen until they enter it. Passwords are **encrypted at rest** with your project security key.

## Why Sesame

- **Field-first:** protection = the field has a value. No section config or template code.
- **Encrypted, not plaintext:** the value lives encrypted in the database, not in config or templates.
- **Editor-driven:** editors set passwords in the element editor, no per-page developer work.
- **Safe defaults:** constant-time comparison, per-IP/element rate limiting, `no-store` + `noindex` on protected responses, shared unlock when passwords match, and a CP-user bypass so live preview keeps working.

## Requirements

Craft CMS 5.10+ and PHP 8.2+.

## Installation

```bash
composer require bensomething/craft-sesame
```

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
- **Rate-limited per IP + element:** Behind a proxy or CDN, make sure Craft is configured to see the real client IP.

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

## Note on Safari

When editing in Safari, iCloud Keychain may offer to save the field's value as a site password. It keys on the field's label and has no markup-level opt-out — name the field anything other than "Password" (e.g. "Passphrase" or "Access code") to avoid the prompt. Firefox and Chrome are unaffected.

## License

MIT
