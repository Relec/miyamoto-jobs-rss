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

After the feed is fetched successfully, the plugin deletes posts previously created by this importer and recreates the current feed items. This keeps the JetEngine custom post type matched to the current UKG listings. It does not delete manually created posts unless they have the internal `_miyamoto_job_imported` marker.

The plugin writes these meta fields:

| RSS field | JetEngine meta key |
| --- | --- |
| `title` | `title` |
| `link` | `link` |
| `category` | `category` |
| `pubDate` | `pubDate` |
| `jobLocationType` | `jobLocationType` |
| `description` | `_description` |

The `pubDate` value is saved in `datetime-local` format, for example `2026-06-04T10:37`.

It also stores internal tracking meta:

| Meta key | Purpose |
| --- | --- |
| `_miyamoto_job_guid` | Stable RSS item ID used for updates |
| `_miyamoto_job_imported` | Marks posts created by this importer |
| `_miyamoto_job_last_seen` | Last successful import time for the job |

## Current Listings Only

If a job disappears from UKG, it disappears from WordPress on the next successful import because the imported posts are replaced from the current feed.

## Description Formatting

The `_description` meta field is stored as plain text with line breaks between:

- Location
- Posted date
- Job summary

Browsers collapse plain-text line breaks unless CSS tells them not to. The plugin includes CSS for this class:

```txt
miyamoto-job-description
```

In your JetEngine Listing template, add that class to the Dynamic Field or wrapper that outputs `_description`. That will render the line breaks without changing the stored data.

If you want paragraph markup instead, replace the `_description` Dynamic Field with this shortcode:

```txt
[miyamoto_job_description]
```

The shortcode outputs safe HTML paragraphs and bold labels for `Location:` and `Posted:`.

## Notes

The plugin does not create the JetEngine post type or meta fields. Keep those configured in JetEngine, then point this plugin at that post type slug.
