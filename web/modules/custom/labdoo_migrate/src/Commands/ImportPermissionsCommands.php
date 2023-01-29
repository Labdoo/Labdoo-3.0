<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\PermissionHandlerInterface;
use Drush\Commands\DrushCommands;
use Drupal\user\Entity\Role;

/**
 * Imports permissions from a CSV file.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ImportPermissionsCommands extends DrushCommands {

  private const RID_TO_NAME = [
    '3' => 'administrator',
    '1' => 'anonymous',
    '2' => 'authenticated',
    '5' => 'edoovillage_manager',
    '6' => 'hub_manager',
    '10' => 'laptop_manager',
    '7' => 'newsletter_manager',
    '8' => 'superhub_manager',
    '9' => 'team_manager',
    '4' => 'wiki_writer',
  ];

  private const ROLE_V2_TO_V3 = [
    'administrator' => 'administrator',
    'anonymous user' => 'anonymous',
    'authenticated user' => 'authenticated',
    'edoovillage manager' => 'edoovillage_manager',
    'hub manager' => 'hub_manager',
    'laptop manager' => 'laptop_manager',
    'newsletter manager' => 'newsletter_manager',
    'superhub manager' => 'superhub_manager',
    'team manager' => 'team_manager',
    'wiki writer' => 'wiki_writer',
  ];

  /**
   * Permissions service.
   *
   * @var \Drupal\user\PermissionHandlerInterface
   */
  protected PermissionHandlerInterface $permissionHandler;

  /**
   * All the permissions of the system.
   *
   * @var array
   */
  protected array $allPermissions;

  /**
   * Indicates whether the CSV file has a header or not.
   *
   * @var bool
   */
  protected bool $hasHeader;

  /**
   * ImportPermissionsCommands constructor.
   *
   * @param \Drupal\user\PermissionHandlerInterface $permissionHandler
   *   Permissions service.
   */
  public function __construct(PermissionHandlerInterface $permissionHandler) {
    parent::__construct();
    $this->permissionHandler = $permissionHandler;
    $this->allPermissions = $this->permissionHandler->getPermissions();
  }

  /**
   * Imports permissions from a CSV file.
   *
   * @param string $file
   *   Path to the CSV file. For example: "sites/default/files/permissions.csv".
   * @param bool $hasHeader
   *   Indicates whether the CSV file has a header or not (optional).
   *
   * @command labdoo_migrate:import-permissions
   * @aliases import-permissions
   * @usage labdoo_migrate:import-permissions /path/to/file.csv
   *   Imports the permissions defined in the CSV file.
   */
  public function importPermissions(
    string $file,
    bool $hasHeader = TRUE
  ): void {
    if (!$this->fileExists($file)) {
      return;
    }

    if (($handle = $this->openFile($file)) === FALSE) {
      return;
    }

    $this->hasHeader = $hasHeader;

    $this->processFile($handle);

    fclose($handle);
    $this->logger()->notice('Finished importing permissions from CSV file.');
  }

  /**
   * Checks if the file exists.
   *
   * @param string $file
   *   The file path to check.
   *
   * @return bool
   *   TRUE if the file exists, FALSE otherwise.
   */
  protected function fileExists(string $file): bool {
    if (!file_exists($file)) {
      $this->logger()->error('The file does not exist: ' . $file);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Opens the CSV file.
   *
   * @param string $file
   *   The file path to open.
   *
   * @return resource|false
   *   Returns a file handle resource on success, or FALSE on error.
   */
  protected function openFile(string $file) {
    if (($handle = fopen($file, 'r')) === FALSE) {
      $this->logger()->error("The file could not be opened: {$file}");

      return FALSE;
    }

    return $handle;
  }

  /**
   * Processes the CSV file line by line.
   *
   * @param resource $handle
   *   The file handle resource.
   *
   * @return void
   *   No return value.
   */
  protected function processFile($handle): void {
    $lineNumber = 0;
    while (($data = fgetcsv($handle, 0, ',')) !== FALSE) {
      $lineNumber++;

      // If CSV has a header, skip the first line
      if ($this->hasHeader && $lineNumber === 1) {
        continue;
      }

      if ($this->validateLineStructure($data, $lineNumber) === FALSE) {
        continue;
      }

      $rid = trim($data[0]);
      $permission = trim($data[1]);
      if ($this->validateLineData($rid, $permission, $lineNumber) === FALSE) {
        continue;
      }

      if ($this->validatePermission($permission, $rid, $lineNumber) === FALSE) {
        continue;
      }

      if (($role = $this->loadRole($rid, $lineNumber)) === FALSE) {
        continue;
      }

      $this->addPermissionToRole($role, $permission, $lineNumber);
    }
  }

  /**
   * Validates the CSV line structure.
   *
   * @param array $data
   *   The CSV row data.
   * @param int $lineNumber
   *   The current line number.
   *
   * @return bool
   *   TRUE if the line structure is valid, FALSE otherwise.
   */
  protected function validateLineStructure(array $data, int $lineNumber): bool {
    // The expected format is $data[0] = rid, $data[1] = permission, $data[2] = module (optional).
    if (count($data) < 2) {
      $this->logger()->error("Line {$lineNumber} has an invalid format. At least 2 columns needed: rid,permission.");

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validates the individual data within a CSV line.
   *
   * @param mixed $rid
   *   The role identifier.
   * @param mixed $permission
   *   The permission name.
   * @param int $lineNumber
   *   The current line number.
   *
   * @return bool
   *   TRUE if the data is valid, FALSE otherwise.
   */
  protected function validateLineData(
    mixed $rid,
    mixed $permission,
    int $lineNumber
  ): bool {
    if (empty($rid) || empty($permission)) {
      $this->logger()->error("Line {$lineNumber} has no valid data.");

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if a permission exists within the system permissions.
   *
   * @param string $permission
   *   The permission to validate.
   * @param mixed $rid
   *   The role ID.
   * @param int $lineNumber
   *   The current line number.
   *
   * @return bool
   *   TRUE if the permission exists, FALSE otherwise.
   */
  protected function validatePermission(
    string $permission,
    $rid,
    int $lineNumber
  ): bool {
    if (!isset($this->allPermissions[$permission])) {
      $similar = $this->findSimilarPermissions($permission);
      if (!empty($similar)) {
        $this->logger()->warning("The permission '{$permission}' does not exist for role '{$rid}'. Did you mean: " . implode(', ', $similar) . "? Line: {$lineNumber}");
      }
      else {
        #$this->logger()->error("The permission '{$permission}' does not exist for role '{$rid}'. Line: {$lineNumber}");
        $this->logger()->error('"' . $permission . '";"' . $rid . '"');
      }

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Loads the role by its identifier.
   *
   * @param string $rid
   *   The role identifier.
   * @param int $lineNumber
   *   The current line number.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Drupal\Core\Entity\EntityBase|\Drupal\user\Entity\Role|false
   *   The loaded role object, or FALSE if it doesn't exist.
   */
  protected function loadRole(
    string $rid,
    int $lineNumber
  ): Role|EntityBase|EntityInterface|false {
    $roleName = $this->getRoleName($rid);
    if (!$roleName) {
      $this->logger()->error("Role '{$rid}' does not exist. Line: {$lineNumber}");

      return FALSE;
    }

    $role = Role::load($roleName);
    if (!$role) {
      $this->logger()->error("Role '{$rid}' does not exist. Line: {$lineNumber}");

      return FALSE;
    }

    return $role;
  }

  /**
   * Retrieves the name of a role based on its role ID.
   *
   * @param string $rid
   *   The role ID to retrieve the name for.
   *
   * @return string|NULL
   *   The name of the role corresponding to the given role ID.
   *   If the role is a string, the mapped role.
   *   If no role is found, NULL.
   */
  protected function getRoleName(string $rid): ?string {
    $roleName = is_numeric($rid)
      ? self::RID_TO_NAME[$rid]
      : self::ROLE_V2_TO_V3[$rid];

    return $roleName ?? NULL;
  }

  /**
   * Finds similar permissions based on a simple similarity threshold.
   *
   * @param string $permission
   *   The permission that doesn't exist.
   * @param int $threshold
   *   The minimum similarity percentage (0-100).
   *
   * @return array
   *   An array of similar permission names.
   */
  protected function findSimilarPermissions(string $permission, int $threshold = 60): array {
    $similarPermissions = [];
    // Compare the given permission with each available one
    foreach ($this->allPermissions as $existingPermission => $_) {
      $similarity = 0;
      similar_text($permission, $existingPermission, $similarity);
      // If similarity is above the threshold, consider it a possible match
      if ($similarity >= $threshold) {
        $similarPermissions[] = $existingPermission;
      }
    }
    return $similarPermissions;
  }

  /**
   * Adds a permission to the given role if not already present.
   *
   * @param \Drupal\user\Entity\Role $role
   *   The role to which the permission is added.
   * @param string $permission
   *   The permission to add.
   * @param int $lineNumber
   *   The current line number.
   *
   * @return void
   *   No return value.
   */
  protected function addPermissionToRole(
    Role $role,
    string $permission,
    int $lineNumber
  ): void {
    $existingPermissions = $role->getPermissions();
    if (in_array($permission, $existingPermissions)) {
      #$this->logger()->notice("Role '{$role->id()}' already had the permission '{$permission}'. Line {$lineNumber}.");

      return;
    }

    $existingPermissions[] = $permission;
    $role->set('permissions', $existingPermissions);
    try {
      $role->save();
    } catch (EntityStorageException $e) {
      $this->logger()->notice("Error adding permission '{$permission}' to role '{$role->id()}'. Line {$lineNumber}. Error: {$e->getMessage()}.");

      return;
    }

    #$this->logger()->notice("Permission '{$permission}' added to role '{$role->id()}'. Line {$lineNumber}.");
  }

}
