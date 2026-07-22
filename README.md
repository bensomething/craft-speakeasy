# Speakeasy

Per-element password protection for Craft CMS. Add a **Password** field to an entry type's field layout, and once a password has been set, anonymous visitors get an unlock screen until they enter it. Passwords are **encrypted at rest** with your project security key.

> [!NOTE]
> **Speakeasy is in beta.** It's feature-complete and safe to try, but the API, settings, and stored-value formats may still change before 1.0.0. Please [report anything you hit](https://github.com/bensomething/craft-speakeasy/issues).

> **These are shared access passwords, not user credentials.** They're reversibly encrypted so editors can view and share them, deliberately *not* one-way hashed like a login password. Speakeasy gates a page behind a shared passphrase, it doesn't authenticate individual users. If you need per-user login, use Craft's user accounts.

## Why Speakeasy

- **Field-first:** protection = the field has a value. No section config or template code.
- **Encrypted, not plaintext:** the value lives encrypted in the database, not in config or templates.
- **Editor-driven:** editors set passwords in the element editor, no per-page developer work.
- **Safe defaults:** constant-time comparison, per-IP/element rate limiting, `no-store` + `noindex` on protected responses, shared unlock when passwords match, and a CP-user bypass so live preview keeps working.

## Requirements

Craft CMS 5.10+ and PHP 8.2+.

## Installation

```bash
composer require bensomething/craft-speakeasy:^1.0.0-beta
```

The `-beta` in the constraint is what lets Composer install it under a project's default `stable` minimum stability.

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
- **Not element-less routes:** Pages rendered by a custom route or a standalone template, with no element behind them, have nothing to hold a password and so can't be gated. Protection is tied to the element it protects, by design. The password lives on the same record as the content.
- **Not static files:** Assets are served without Craft in the request, so the gate never runs. Protecting them is a separate problem, the usual approach is a private filesystem with a controller that authorises and streams each file.
- **Not other queries:** A protected element's fields shown in a listing, relation, eager-loaded loop, GraphQL, or the Element API are not gated, that's up to your templates (see [Note on GraphQL and the API](#note-on-graphql-and-the-api)).
- **Never outputs the password:** `{{ entry.<handle> }}` prints `••••••••`, and the value is kept out of the search index and GraphQL schema. Twig can't unwrap it either, templates only ever get the mask. The plaintext is reachable only from Speakeasy's own PHP, which the gate uses to compare.
- **Fail-closed on key loss:** If the security key is rotated or lost, existing passwords can't be decrypted and those elements stay locked. Re-enter passwords after a key change.
- **Unlocks live in the visitor's session:** They end when the browser closes, and PHP may expire an idle session sooner (`session.gc_maxlifetime`, often 24 minutes). Unlock duration sets an upper bound on top of that, it can't extend an unlock beyond the session itself, so an unlock lasts for whichever ends first.
- **Rate-limited per IP + element:** Behind a proxy or CDN, make sure Craft is configured to see the real client IP. Rate limiting relies on Craft's cache, so a null/dummy cache driver disables the lockout.

## Settings

Settings are split across two tabs. **General:**

| Setting | Default | Purpose |
| --- | --- | --- |
| Lockdown | off | Close every protected element at once, environment variable only (see [Lockdown](#lockdown)) |
| Bypass for control-panel users | on | Signed-in users who can view the element skip the gate |
| Unlock duration | 0 | How long an unlock lasts, in seconds (0 = the whole browsing session) |
| Max unlock attempts | 5 | Failed tries per IP + element before lockout (0 disables) |
| Lockout window | 300 | Lockout duration / attempt-count expiry, in seconds |

**Appearance:**

| Setting | Default | Purpose |
| --- | --- | --- |
| Custom unlock template | *(bundled)* | Override the unlock screen with your own site template |
| Placeholder text | Password | Placeholder in the bundled screen's password field |
| Button text | Enter | Label on the bundled screen's submit button |
| Error text | Incorrect password | Message shown after a failed unlock |
| Lockdown text | This page is currently locked. | Message shown while lockdown is on |
| Unlock screen CSS | *(bundled variables)* | Restyle the bundled screen by overriding its CSS variables |

The **Placeholder text**, **Button text**, **Error text**, and **Lockdown text** settings are the bundled screen's copy, and **Unlock screen CSS** is its styling. All five apply to the bundled screen only, so they're hidden and stop taking effect when a **Custom unlock template** is set, which owns its own copy and styling. Each text field falls back to its default (shown above) when left blank.

### Setting them in a config file

Every setting above except **Lockdown** can be set in `config/speakeasy.php`. The plugin ships a commented starter at [`src/config.php`](src/config.php) covering every option, so the quickest way in is to copy it:

```bash
cp vendor/bensomething/craft-speakeasy/src/config.php config/speakeasy.php
```

Everything in it is commented out, so copying it changes nothing until you uncomment something. The property names are:

```php
return [
    'maxAttempts' => 3,
    'attemptWindowSeconds' => 600,
    'placeholderText' => 'Enter the password',
];
```

| Setting | Property |
| --- | --- |
| Bypass for control-panel users | `bypassForCpUsers` |
| Unlock duration | `unlockDurationSeconds` |
| Max unlock attempts | `maxAttempts` |
| Lockout window | `attemptWindowSeconds` |
| Custom unlock template | `template` |
| Placeholder text | `placeholderText` |
| Button text | `buttonText` |
| Error text | `errorText` |
| Lockdown text | `lockdownText` |
| Unlock screen CSS | `customCss` |

Craft's [multi-environment config](https://craftcms.com/docs/5.x/configure.html#multi-environment-configs) works as usual:

```php
return [
    '*' => ['maxAttempts' => 5],
    'dev' => ['maxAttempts' => 0],
];
```

Anything named there wins over what's saved in the control panel, on every load. Those fields are shown disabled with a note saying so, since an edit would otherwise save and then silently revert. The stored value underneath is left intact and returns if you remove the key from the file.

**Lockdown** is the exception. It isn't a stored setting at all, so a `lockdown` key here does nothing. Use the `SPEAKEASY_LOCKDOWN` environment variable instead.

### Lockdown

Closes every protected element at once. Visitors get a message instead of the unlock screen, no password is accepted, and anyone already unlocked is shut out too. Protected responses return `403` while it's on. Elements without a password are unaffected, and control-panel users still bypass it if **Bypass for control-panel users** is on, so editors and live preview keep working.

Set it with the `SPEAKEASY_LOCKDOWN` environment variable:

```bash
SPEAKEASY_LOCKDOWN=true
```

`true`/`false` and `1`/`0` all work. Unset and empty both mean off, so a shared `.env` template can ship the key blank. Any other non-empty value counts as on, erring towards locked rather than open.

This is the only way to set it. Lockdown isn't a stored setting, so it can't be set from the control panel or from `config/speakeasy.php`, and a stray `lockdown` key in either is ignored. The settings screen shows the current state and whether the environment set it, but has no control to change it.

That's deliberate. Plugin settings live in project config, which is shared across environments, so a stored value would carry a lockdown from wherever it was set to everywhere else on the next deploy, and a control-panel switch would only work where admin changes are allowed, which shouldn't include production. An environment variable is per-environment, isn't committed, and takes effect without deploying anything.

Lockdown is a curtain, not a revocation. Existing unlocks aren't cleared, they're ignored, and they resume working the moment it's switched off. To actually end them, change the passwords, which invalidates every unlock derived from them, or lock down and wait out the session-expiry window before lifting it.

A custom **Unlock template** receives a `lockdown` variable so it can render its own locked state. It doesn't have to: the `speakeasy/unlock` action refuses to run under lockdown, so a template that still shows its form just can't be used to get in.

### Restyling the bundled screen

Without replacing the template, you can retheme the bundled unlock screen from the **Unlock screen CSS** field by overriding its CSS variables:

```css
:root {
    --speakeasy-background: #101418;
    --speakeasy-button-background: #4a7dff;
}
```

Available variables: `--speakeasy-background`, `--speakeasy-input-background`, `--speakeasy-text`, `--speakeasy-placeholder-text`, `--speakeasy-input-border`, `--speakeasy-input-border-focus`, `--speakeasy-button-background`, `--speakeasy-button-text`, `--speakeasy-button-background-hover`, `--speakeasy-error-text`, `--speakeasy-radius`, `--speakeasy-font`. If the [CKEditor plugin](https://github.com/craftcms/ckeditor) (or anything else depending on `nystudio107/craft-code-editor`) is installed, the field upgrades to a syntax-highlighting Monaco editor. Otherwise it's a plain code textarea.

### Custom unlock template

Point the **Custom unlock template** setting at a site template. It receives an `element` variable and must post to the `speakeasy/unlock` action:

```twig
<form method="post">
    <input type="hidden" name="action" value="speakeasy/unlock">
    <input type="hidden" name="elementId" value="{{ element.id }}">
    {{ csrfInput() }}
    <input type="password" name="password" autofocus>
    <button type="submit">{{ 'Enter'|t }}</button>
</form>
```

## Note on GraphQL and the API

The gate only runs when Craft renders an element's URL. It does **not** apply to GraphQL, the Element API, or any decoupled/headless front-end. The password itself is excluded from the schema (it can't be selected), and unlocking is a server-side session flag with no API equivalent. But a protected element's **other** fields stay readable through any API whose scope includes them. Gating API-consumed content is your app's job.

To keep protected content out of an API, prefer scope: leave the section out of your GraphQL token / public schema. If you can't, filter it out. The field handle is exposed as a presence-only query argument (a Craft-wide behaviour), so:

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

The bundled unlock screen is a self-contained page (Speakeasy swaps the whole response, so none of your site's CSS or JS loads on it). It adapts to light/dark via the visitor's **OS/browser** preference (`@media (prefers-color-scheme: dark)`), which works everywhere without any cooperation from your templates. It does **not** follow a site's manual theme toggle (a `.dark` class, a `data-theme` attribute, a cookie), because that toggle's JS never runs on the unlock screen. If a visitor's OS is light but they've switched your site to dark, the unlock screen still shows light. To mirror a manual toggle, set a **Custom unlock template** so the screen renders inside your own layout, where your theme logic applies.

## Note on Safari

When editing in Safari, iCloud Keychain may offer to save the field's value as a site password. It keys on the field's label and has no markup-level opt-out, so name the field anything other than "Password" (e.g. "Passphrase" or "Access code") to avoid the prompt. Firefox and Chrome are unaffected.

## License

MIT
