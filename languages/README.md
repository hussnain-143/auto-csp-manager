# Translations

`auto-csp-manager.php` calls `load_plugin_textdomain()` against this directory, so a
`.mo` file dropped in here is picked up automatically:

    auto-csp-manager-<locale>.mo      e.g. auto-csp-manager-de_DE.mo

Plugins hosted on WordPress.org also receive translations from translate.wordpress.org,
which WordPress loads from its own languages directory. This folder is for translations
shipped with the plugin itself.

The directory is kept in the release package so a site owner can add a `.mo` file without
creating it first.
