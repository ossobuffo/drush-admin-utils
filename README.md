# Drush Admin Utilities

These commands are not alias-aware, and thus can only be used locally.
This is by design, because they run at a low boot-level. Thus, all these
commands may be used to hack under the hood of a non-bootable Drupal install.

## Truncate caches
Usage:
`drush dau:cache-truncate`

Truncates all MySQL cache tables.

## Clean up system schema
Usage:
`drush dau:schema-clean`

Removes system.schema entries for any modules or profiles that are not enabled.

## Forcibly uninstall modules
Usage:
`drush dau:module-uninstall-force ‹module›`

Forcibly removes a module from core.extension config, whether or not the module’s
code is currently available. Any related system.schema value is also removed.

**DANGER WILL ROBINSON!** No dependency checking and no further schema or
config cleanup is performed. Use with extreme care!

## Forcibly uninstall themes
Usage:
`drush dau:theme-uninstall-force ‹theme›`

Forcibly removes a theme from core.extension config, whether or not the theme
currently exists.

As with dau:module-uninstall-force, no dependency checking or further schema/config
cleanup is performed. Use with extreme care!

## Switch profiles
Usage:
`drush dau:profile-switch ‹profile›`

Switches to a new profile. Inspiration for this command came from Drupal’s
contrib [Profile switcher](http://www.drupal.org/project/profile_switcher)
module.
