<?php

/**
 * Speakeasy config
 *
 * Copy this file to `config/speakeasy.php` and uncomment what you want to set.
 * Anything named here wins over the plugin's settings screen on every load, and
 * those fields are shown there disabled so an edit can't silently revert.
 *
 * Values shown are the defaults.
 *
 * Lockdown is deliberately absent. It isn't a stored setting, so a `lockdown`
 * key here does nothing. Use the SPEAKEASY_LOCKDOWN environment variable.
 */

return [
    // Multi-environment configs work as usual. Wrap the settings below in
    // '*' => [...] and add per-environment overrides alongside it.

    // Signed-in users who can view the element in the CP skip the unlock screen.
    // Keeps live preview usable.
    //'bypassForCpUsers' => true,

    // Mark protected elements with a padlock wherever the CP lists them: element
    // indexes, relation fields and element selects.
    //'showLockIcon' => true,

    // How long an unlock lasts before the password is asked for again. 0 keeps it
    // for the whole browsing session. Can't outlive the session itself, so an
    // unlock ends at whichever comes first.
    //'unlockDurationSeconds' => 0,

    // Failed attempts per visitor IP + password before a lockout, and how long
    // both the count and the lockout last. maxAttempts of 0 disables rate
    // limiting. Relies on Craft's cache, so a null cache driver disables it too.
    //'attemptWindowSeconds' => 300,
    //'maxAttempts' => 5,

    // A site template to render instead of the bundled unlock screen. It receives
    // `element` and `lockdown`, and must post to the `speakeasy/unlock` action.
    // When set, every bundled-screen setting below stops applying, since a custom
    // template owns its own copy and styling.
    //'template' => '',

    // The bundled screen's copy. Each falls back to its translatable default when
    // left empty, so setting these here freezes them to one language.
    //'placeholderText' => '',   // Password
    //'buttonText' => '',        // Enter
    //'errorText' => '',         // Incorrect password
    //'lockdownText' => '',      // This page is currently locked.

    // CSS appended to the bundled screen, for overriding its variables. Empty
    // means the bundled defaults. See the readme for the full variable list.
    //'customCss' => ':root { --speakeasy-background: #101418; }',
];
