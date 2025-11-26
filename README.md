# Interests CiviCRM Bridge

Synchronizes the profile field that stores Areas of Interest with a matching
CiviCRM custom field so that changes in either system stay aligned.

## Features

- Watches the configured Profile bundle/field (defaults to the `main` profile
  and `field_member_areas_interest`) and updates the configured CiviCRM custom
  field whenever the taxonomy selection changes.
- Listens for `hook_civicrm_post` events on contacts; when the mapped custom
  field changes inside CiviCRM, the matching Drupal profile is updated.
- Optionally auto-creates the profile record when pulling from CiviCRM if the
  member does not already have one.
- Re-uses UFMatch to resolve Drupal users ↔ CiviCRM contacts and falls back to
  an email lookup if needed.

## Configuration

1. Enable the module.
2. Visit **Configuration → People → Interests <-> CiviCRM sync**.
3. Enter the profile bundle and field machine names if you are not using the
   defaults.
4. Provide the CiviCRM custom field identifier (column name, numeric ID, or
   machine name). The field must be a multi-value option field whose option
   group shares labels with the Drupal taxonomy terms.
5. Decide whether Drupal should push to CiviCRM and/or pull from CiviCRM.

After saving, edits to either platform will propagate automatically. If you add
new taxonomy terms or CiviCRM option values, ensure their labels continue to
match so the automatic mapper can find them.

## Bulk backfill

When first rolling out the CiviCRM field, seed it with existing Drupal data:

```
drush interests:push-interests
```

Use `--limit` or `--chunk` options if you need to throttle the run. The command
respects the configured profile bundle/field and reuses the same mapping logic
as real-time syncs.
