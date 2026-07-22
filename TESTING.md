# Testing Speakeasy

Thanks for trying this. Speakeasy is in beta, so the most useful thing you can do is work through the checks below on a real install and tell me what doesn't match.

Everything here is manual and browser-based on purpose. The automated suite covers the logic underneath ([`composer test`](composer.json) if you want to run it), but it deliberately stops short of rendering, the control-panel UI, and the field's JavaScript. That's the part that needs a person.

Nothing here should take longer than fifteen minutes.

## Before you start

- A Craft 5.10+ install you don't mind breaking. **Please don't use a production site**, the security-key check at the end logs everyone out.
- Speakeasy installed: `composer require bensomething/craft-speakeasy:^1.0.0-beta`
- A **Password** field created in **Settings → Fields**, added to the field layout of an entry type whose entries have URLs.
- At least three entries of that type, all viewable on the front end.
- A browser private window for the visitor checks, so you aren't carrying a control-panel login or an existing unlock.

Note your Craft version, PHP version, and Speakeasy version somewhere. They're the first things I'll ask for.

## The gate

- [ ] Set a password on an entry. Visiting its URL in a private window shows the unlock screen.
- [ ] The wrong password is refused and an error message appears.
- [ ] The right password gets you through, and reloading keeps you through.
- [ ] Clearing the password makes the entry public again.
- [ ] Two entries sharing the same password: unlocking one opens the other.
- [ ] Two entries with different passwords: unlocking one does **not** open the other.

## The field in the control panel

- [ ] The saved value renders masked, and the eye icon reveals and re-hides it.
- [ ] The eye icon is hidden while the field is empty.
- [ ] Typing, pasting, selecting and deleting all behave like a normal text field, and the value saves correctly.
- [ ] Turn the field's **Show visibility toggle** setting off. The value now shows as plain text with no eye icon.
- [ ] The element index shows a check in the field's column for protected entries, and nothing for public ones.
- [ ] The same Password field can't be added to one field layout twice. It drops out of the designer's list once placed.
- [ ] Add a second, *different* Password field to the same layout. It warns that only the first one has any effect.
- [ ] Add a Password field to something with no URL of its own (an asset volume, a global set). It warns that a password will have no effect.

## Editors and lockdown

- [ ] Signed into the control panel, live preview shows a protected entry's content rather than the unlock screen.
- [ ] Turn **Bypass for control-panel users** off. A signed-in editor now gets the unlock screen too.
- [ ] Turn it back on, then set `SPEAKEASY_LOCKDOWN=1` in `.env`. A protected entry returns a `403` with the lockdown message instead of the unlock screen.
- [ ] Still locked down, a visitor who had already unlocked is shut out as well.
- [ ] Still locked down, a signed-in editor with bypass on still gets through.
- [ ] Remove `SPEAKEASY_LOCKDOWN`. Everything returns to normal, and the visitor who unlocked earlier is back in without re-entering the password.

## Appearance

- [ ] **Unlock screen CSS** in the plugin settings restyles the bundled unlock screen.
- [ ] **Placeholder text**, **Button text**, **Error text** and **Lockdown text** all appear where you'd expect, and fall back to their defaults when left blank.
- [ ] Set a **Custom unlock template**. It renders instead of the bundled screen, and can read the `element` variable.
- [ ] Setting one hides the bundled screen's text and CSS settings.
- [ ] A custom template using `{% if lockdown %}` shows its own locked state while lockdown is on.
- [ ] A custom template that ignores `lockdown` still shows its form under lockdown, but submitting it is refused. (Both are fine. The point is that a template can't accidentally let someone in.)

## Rate limiting

- [ ] Submit the wrong password more times than **Max unlock attempts**. A too-many-attempts message appears.
- [ ] While locked out, the *right* password is refused too.
- [ ] After **Lockout window** seconds pass, the right password works again.

## Losing the security key

This is the one I most want checked, because it's the failure that would matter most and it's the hardest to reason about. Do it last, on a throwaway install: changing the key signs everyone out and breaks any other encrypted data in the site.

- [ ] Change `CRAFT_SECURITY_KEY` in `.env`, then reload the protected entry as a visitor. It's **still locked**, and the password that used to work no longer does.
- [ ] In the control panel, the entry's Password field is empty and shows a warning that the key has changed.
- [ ] Save the entry **without touching the Password field**. It's still locked, and the warning is still there.
- [ ] Enter a new password and save. The warning goes, and the new password unlocks the entry.

If the entry ever becomes publicly readable at any point in that sequence, stop and tell me immediately. That's the worst bug this plugin can have.

## What to report

[Open an issue](https://github.com/bensomething/craft-speakeasy/issues) with:

- Which check failed, and what happened instead
- Craft version, PHP version, Speakeasy version
- Anything unusual about the install: a CDN or reverse proxy in front, static caching, a non-default cache driver, headless mode

Anything that surprised you is worth reporting even if every box above ticks. Confusing wording, a setting that didn't do what its label implied, or a warning that appeared when it shouldn't are all useful. So is telling me it all worked, since that's information too and very nice to hear!
