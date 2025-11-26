# Interests CiviCRM Bridge

## Project Overview
**Type:** Drupal Custom Module  
**Machine Name:** `interests_civi_bridge`  
**Core Compatibility:** Drupal 10 / 11

This module synchronizes a user's "Areas of Interest" between a Drupal Profile entity field (Taxonomy reference) and a corresponding CiviCRM Custom Field. The synchronization is bi-directional:
*   **Drupal to CiviCRM:** Updates CiviCRM when a Profile is saved.
*   **CiviCRM to Drupal:** Updates the Drupal Profile when a CiviCRM contact is modified (via `hook_civicrm_post`).

It resolves the User ↔ Contact relationship using CiviCRM's `UFMatch` (and falls back to email matching).

## Key Features
*   **Real-time Sync:** Listens to entity events to propagate changes immediately.
*   **Profile Auto-creation:** Can create a Drupal Profile record if one doesn't exist when syncing from CiviCRM.
*   **Label Matching:** Matches Drupal Taxonomy terms to CiviCRM Custom Field Options by label/name.

## Key Files & Structure
*   `interests_civi_bridge.module`: Implements Drupal and CiviCRM hooks (e.g., `hook_civicrm_post`).
*   `src/InterestsSyncManager.php`: Core service containing the business logic for mapping and synchronization.
*   `src/Commands/InterestsCiviBridgeCommands.php`: Drush commands for bulk operations.
*   `src/Form/InterestsCiviBridgeSettingsForm.php`: Configuration UI for selecting bundles and fields.

## Setup & Configuration
1.  **Enable:** `drush en interests_civi_bridge`
2.  **Configure:** Go to **Configuration → People → Interests <-> CiviCRM sync**.
    *   **Profile Bundle:** e.g., `main`
    *   **Drupal Field:** e.g., `field_member_areas_interest`
    *   **CiviCRM Field:** The custom field identifier (e.g., `custom_123` or machine name).
3.  **Backfill:** Run the Drush command to push existing Drupal data to CiviCRM.

## CLI Commands (Drush)
Prefix commands with `lando` if working in a Lando environment.

*   **Push Interests (Bulk Sync):**
    ```bash
    drush interests:push-interests
    # Options:
    # --limit=100  (Process only 100 profiles)
    # --chunk=50   (Batch size)
    ```

## Development Conventions
*   **Dependency Injection:** Services (like `InterestsSyncManager`) should be injected into Forms and Drush commands.
*   **Hooks:** Hooks are implemented in the `.module` file but should delegate complex logic to the service class.
*   **Logging:** Use Drupal's logger channel `interests_civi_bridge` for debugging sync issues.
