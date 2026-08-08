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

## Onboarding picker (thank-you page)

`interests_civi_bridge_node_view()` injects `InterestPickerForm` at the top of
the onboarding thank-you page (node 614), so interests are captured at the end
of signup rather than on the join form. This page is the only deferred-field
capture point with a proven 100% completion rate.

Because of that, the picker also carries **"How did you discover MakeHaven?"**
(`field_member_discovery`, plus the `field_member_referring` and
`field_member_discovery_event_det` follow-ups). That question used to live on
the join profile form; the 2026-07-24 funnel shrink deferred it with the other
community/marketing fields and capture fell from ~85-90% to 0 — deferred fields
only reappear on `/profile/N/edit` once a member has a door badge, which nobody
visits unprompted (staff feedback, 2026-08-04).

Notes for maintainers:

- The question is asked **only when the field is empty**, so campaign links that
  prepopulate it through the EPP `?discovery=` token are not re-asked.
- Stored *values* match the field's own allowed values, so
  `views.view.discovery_report` and all historical records keep working. Only
  the member-facing wording differs (see `discoveryLabels()`); any option on the
  field that is missing from that map still renders, using its field label.
- The question is optional on purpose. Interests are this page's primary job,
  and a required question risks losing both answers to an abandoned form. If
  capture is low, making it required is a one-line change.

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
