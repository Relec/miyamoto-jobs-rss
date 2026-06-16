# Miyamoto Jobs Importer WordPress Plugin

This plugin imports the generated Miyamoto jobs RSS feed into a JetEngine custom post type.

Default feed URL:

```txt
https://relec.github.io/miyamoto-jobs-rss/jobs.xml
```

## Install

1. Zip the `miyamoto-jobs-importer` folder.
2. In WordPress Admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the zip file and activate the plugin.
4. Go to **Settings > Miyamoto Jobs Importer**.
5. Enter the JetEngine post type slug for your jobs custom post type.
6. Click **Save Settings**.
7. Click **Run import now**.

## Schedule

The importer runs every 6 hours using WP-Cron. WP-Cron depends on site traffic. If the site has very low traffic, configure a server cron to call `wp-cron.php`.

## Field Mapping

The plugin creates or updates posts in the configured custom post type and writes these meta fields:

| RSS field | JetEngine meta key |
| --- | --- |
| `title` | `title` |
| `link` | `link` |
| `category` | `category` |
| `description` | `_description` |

It also stores internal tracking meta:

| Meta key | Purpose |
| --- | --- |
| `_miyamoto_job_guid` | Stable RSS item ID used for updates |
| `_miyamoto_job_imported` | Marks posts created by this importer |
| `_miyamoto_job_last_seen` | Last successful import time for the job |

## Missing Jobs

By default, imported jobs that disappear from the RSS feed are moved to Draft. You can change this in the settings page to leave them unchanged or move them to Trash.

## Notes

The plugin does not create the JetEngine post type or meta fields. Keep those configured in JetEngine, then point this plugin at that post type slug.
