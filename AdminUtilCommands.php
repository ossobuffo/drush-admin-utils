<?php

namespace Drush\Commands\dau;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\CommandFailedException;

/**
 * Command file for admin utilities.
 */
class AdminUtilCommands extends DrushCommands {

  protected Connection $db;

  /**
   * Truncates all cache_* database tables.
   */
  #[CLI\Command(name: 'dau:cache-truncate', aliases: ['dau-ct'])]
  #[CLI\Help(description: 'Truncate all cache database tables')]
  #[CLI\Usage(name: 'dau:cache-truncate', description: 'Truncate all cache database tables')]
  #[CLI\Bootstrap(level: DrupalBootLevels::DATABASE)]
  public function truncateDbCaches(): void {
    $this->ensureDb();
    $this->truncateCache(TRUE);
  }

  /**
   * Cleans up orphaned schema entries.
   */
  #[CLI\Command(name: 'dau:schema-clean', aliases: ['dau-sc'])]
  #[CLI\Help(description: 'Cleans up orphaned system.schema entries.')]
  #[CLI\Usage(name: 'dau:schema-clean', description: 'Cleans up orphaned system.schema entries.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::DATABASE)]
  public function cleanupSchema(): void {
    $this->ensureDb();
    $schema_modules = $this->db->select('key_value', 'kv')
      ->fields('kv', ['name'])
      ->condition('collection', 'system.schema')
      ->execute()
      ->fetchCol();
    if (empty($schema_modules)) {
      throw new CommandFailedException("Unexpected error: No system.schema values found.");
    }

    $ext_conf = $this->getExtConfig();
    if (!isset($ext_conf['module'])) {
      throw new CommandFailedException("Corrupt core.extension configuration.");
    }
    $enabled_modules = array_keys($ext_conf['module']);
    $bad_schema_entries = array_diff_key($schema_modules, $enabled_modules);

    if (empty($bad_schema_entries)) {
      $this->io()->success("No orphaned system.schema entries were found.");
      return;
    }

    foreach ($bad_schema_entries as $module) {
      $this->db->delete('key_value')
        ->condition('collection', 'system.schema')
        ->condition('name', $module)
        ->execute();
      $this->io()->warning("Removed system.schema entry for $module");
    }
    $this->truncateCache();
  }

  /**
   * Forcibly uninstalls a module.
   */
  #[CLI\Command(name: 'dau:module-uninstall-force', aliases: ['dau-muf'])]
  #[CLI\Help(description: 'Forcibly removes a module from the core.extension and system.schema configuration. USE WITH EXTREME CARE! No dependency checking and no further schema or config cleanup is performed.')]
  #[CLI\Usage(name: 'dau:module-uninstall-force <module>', description: 'Forcibly removes a module from the core.extension and system.schema configuration.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::DATABASE)]
  #[CLI\Argument(name: 'module', description: 'The module to forcibly uninstall')]
  public function forceModuleUninstall(string $module): void {
    $this->ensureDb();
    $ext_conf = $this->getExtConfig();
    if (!isset($ext_conf['module'])) {
      throw new CommandFailedException("Corrupt core.extension configuration.");
    }
    if (!array_key_exists($module, $ext_conf['module'])) {
      $this->io()->warning("Module '$module' already not enabled.");
    }
    unset($ext_conf['module'][$module]);
    $this->setExtConfig($ext_conf);

    $this->db->delete('key_value')
      ->condition('collection', 'system.schema')
      ->condition('name', $module)
      ->execute();
    $this->truncateCache();
    $this->io()->success("Module '$module' was forcibly uninstalled.");
  }

  /**
   * Forcibly uninstalls a theme.
   */
  #[CLI\Command(name: 'dau:theme-uninstall-force', aliases: ['dau-tuf'])]
  #[CLI\Help(description: 'Forcibly removes a theme from the core.extension configuration. USE WITH EXTREME CARE! No dependency checking and no further schema or config cleanup is performed.')]
  #[CLI\Usage(name: 'dau:theme-uninstall-force <theme>', description: 'Forcibly removes a theme from the core.extension configuration.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::DATABASE)]
  #[CLI\Argument(name: 'theme', description: 'The theme to forcibly uninstall')]
  public function forceThemeUninstall(string $theme): void {
    $this->ensureDb();
    $ext_conf = $this->getExtConfig();
    if (!isset($ext_conf['theme'])) {
      throw new CommandFailedException("Corrupt core.extension configuration.");
    }
    if (!array_key_exists($theme, $ext_conf['theme'])) {
      $this->io()->warning("Theme '$theme' already not enabled.");
    }
    unset($ext_conf['theme'][$theme]);
    $this->setExtConfig($ext_conf);
    $this->truncateCache();
    $this->io()->success("Theme '$theme' was forcibly uninstalled.");
  }

  /**
   * Switches to a new profile.
   */
  #[CLI\Command(name: 'dau:profile-switch', aliases: ['dau-ps'])]
  #[CLI\Help(description: 'Switches to a new profile, and removes the old one.')]
  #[CLI\Usage(name: 'dau:profile-switch <profile>', description: 'Switches to a new profile, and removes the old one.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::DATABASE)]
  #[CLI\Argument(name: 'profile', description: 'The profile to switch to.')]
  public function switchProfile(string $profile): void {
    $this->ensureDb();
    $ext_conf = $this->getExtConfig();
    if (!isset($ext_conf['profile']) || !is_string($ext_conf['profile'])) {
      throw new CommandFailedException("Corrupt core.extension configuration.");
    }
    $old_profile = $ext_conf['profile'];
    if ($old_profile === $profile) {
      $this->io()->warning("Profile $profile is already set.");
      return;
    }
    $ext_conf['profile'] = $profile;
    unset($ext_conf['module'][$old_profile]);
    $ext_conf['module'][$profile] = 1000;
    $this->setExtConfig($ext_conf);

    $this->db->delete('key_value')
      ->condition('collection', 'state')
      ->condition('name', 'system.profile.files')
      ->execute();

    $this->db->delete('key_value')
      ->condition('collection', 'system.schema')
      ->condition('name', $old_profile)
      ->execute();

    $query = $this->db->select('key_value');
    $query->addExpression('1', 'ct');
    $has_schema = (bool) $query->condition('collection', 'system.schema')
      ->condition('name', $profile)
      ->execute()
      ->fetchField();
    if (!$has_schema) {
      $schema = [
        'collection' => 'system.schema',
        'name' => $profile,
        'value' => 'i:10000;',
      ];
      $this->db->insert('key_value')
        ->fields($schema)
        ->execute();
    }

    $this->truncateCache();
    $this->io()->success("System profile has been changed from '$old_profile' to '$profile'.");
  }

  /**
   * Gets the current db connection and validates that it is supported.
   *
   * @throws \Drush\Exceptions\CommandFailedException
   */
  private function ensureDb(): void {
    if (empty($this->db)) {
      $this->db = Database::getConnection();
      $driver = $this->db->getConnectionOptions()['driver'];
      if ($driver !== 'mysql' && $driver !== 'pgsql' && $driver !== 'sqlite') {
        throw new CommandFailedException("Unsupported database driver '$driver'; this command only supports Drupal core database drivers.");
      }
    }
  }

  /**
   * Truncates all cache_* tables.
   *
   * @param bool $verbose
   *   If TRUE, print to $this->io() for each table truncated.
   *
   * @return void
   */
  private function truncateCache(bool $verbose = FALSE): void {
    if (method_exists($this->db, 'getPrefix')) {
      $prefix = $this->db->getPrefix();
    }
    else {
      // Fall back to pre-Drupal-10.1 syntax.
      $prefix = $this->db->tablePrefix();
    }
    $driver = $this->db->getConnectionOptions()['driver'];
    switch ($driver) {
      case 'mysql':
        $sql = "SHOW TABLES LIKE '{$prefix}cache\\_%'";
        $truncate_sql = 'TRUNCATE TABLE ';
        break;

      case 'pgsql':
        $schema = $this->db->getConnectionOptions()['schema'] ?? 'public';
        $sql = "SELECT tablename FROM pg_tables WHERE schemaname = '$schema' AND tablename LIKE '{$prefix}cache\\_%'";
        $truncate_sql = 'TRUNCATE TABLE ';
        break;

      case 'sqlite':
        $sql = "SELECT name FROM sqlite_schema WHERE type = 'table' AND name LIKE '{$prefix}cache\\_%'";
        $truncate_sql = 'DELETE FROM ';
        break;
    }

    /** @var \Drupal\Core\Database\StatementInterface $stmt */
    $tables = $this->db->query($sql)->fetchCol();
    foreach ($tables as $table) {
      if ($verbose) {
        $this->io()->writeln('Truncating table ' . $table);
      }
      $this->db->query($truncate_sql . $table);
    }
  }

  /**
   * Gets core.extension configuration array.
   *
   * @return array
   *   The core.extension config.
   *
   * @throws \Drush\Exceptions\CommandFailedException
   */
  private function getExtConfig(): array {
    $ext_conf_serialized = $this->db->select('config', 'c')
      ->fields('c', ['data'])
      ->condition('name', 'core.extension')
      ->execute()
      ->fetchField();
    $ext_conf = @unserialize($ext_conf_serialized, ['allowed_classes' => FALSE]);
    if (!is_array($ext_conf) || count($ext_conf) === 0) {
      throw new CommandFailedException("Empty or corrupt core.extension configuration.");
    }
    return $ext_conf;
  }

  /**
   * Saves core.extension config to the database.
   *
   * @param array $config
   *   The core.extension configuration.
   */
  private function setExtConfig(array $config): void {
    $this->db->update('config')
      ->condition('name', 'core.extension')
      ->fields(['data' => serialize($config)])
      ->execute();
  }

}
