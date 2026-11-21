# pc2playlister

A WordPress plugin (plugin name: *Badlands Playlists*) that builds **unofficial, fan-made per-show playlist feeds** (Podcasting 2.0, `podcast:medium` = `podcastL`) from the Badlands Media RSS feed, keeps them up to date on a schedule, and pings [podping.cloud](https://github.com/Podcastindex-org/podping.cloud) when a playlist changes.

The playlists contain only `podcast:remoteItem` references (the original feed's GUID plus each episode's GUID) and a link to the latest episode's artwork. No audio, show notes or artwork is copied or stored: apps look the episodes up from the original publisher's feed.

## How it works

1. A daily WP-Cron job downloads `https://feed.podbean.com/badlandsmedia/feed.xml` (using `ETag` / `If-Modified-Since`).
2. Each episode is assigned to a show from its title prefix (`Show Name: ...`, `Show Name Ep. 12: ...`, `Show Name (S3E7): ...`, `Show Name [1.23]: ...`).
3. One static file per show is written to `wp-content/uploads/playlists/badlands/<show>.xml`.
4. A playlist is rewritten, and pinged, only when its content actually changed. Failed pings are retried on later runs.

Safeguards: truncated or suspiciously small downloads are refused and existing playlists are left untouched; files are written atomically; overlapping runs are locked out.

## Install

1. Zip the `badlands-playlists` folder (or use a release zip) and upload it via **Plugins → Add New → Upload Plugin**, then activate.
2. Playlists appear at `https://<your-site>/wp-content/uploads/playlists/badlands/<show>.xml`.
3. **Tools → Badlands Playlists** shows status, the show list and a "Run now" button.

The feed URLs default to `phafe.com`. Set `BADLANDS_PLAYLISTS_BASE_URL` and `BADLANDS_PLAYLISTS_LINK` (below) for your own site. Each playlist's `podcast:guid` is derived from its URL, so don't change the URL once feeds are submitted anywhere.

## Configuration (`wp-config.php`)

| Constant | Purpose |
| --- | --- |
| `BADLANDS_PLAYLISTS_PODPING_TOKEN` | podping.cloud token. Keep it here, not in the database. Without it, playlists still build and pings wait in a queue. |
| `BADLANDS_PLAYLISTS_CONTACT` | Address shown in each description for removal requests. |
| `BADLANDS_PLAYLISTS_SCHEDULE` | `hourly`, `twicedaily` or `daily` (default). |
| `BADLANDS_PLAYLISTS_MIN_EPISODES` | Episodes a show needs before it gets a playlist (default 3). |
| `BADLANDS_PLAYLISTS_EXCLUDE` | Array of show slugs to skip, e.g. `array( 'badlands-media-special-coverage' )`. |
| `BADLANDS_PLAYLISTS_BASE_URL` | Public URL of the playlist folder. |
| `BADLANDS_PLAYLISTS_LINK` | Site URL used as the channel link. |

Map title variants to one show with the `badlands_playlists_aliases` filter:

```php
add_filter( 'badlands_playlists_aliases', function () {
    return array( 'Daily Herold' => 'The Daily Herold' ); // variant => canonical name
} );
```

For a reliable schedule on a low-traffic site, add `define( 'DISABLE_WP_CRON', true );` and have the host's cron call `wp-cron.php` every 5-15 minutes.

## Known limits

- The Badlands feed itself appears to hold only the newest ~1,000 episodes, so older episodes can't be listed from it.
- podping.cloud tokens are issued by Podcast Index on request; there is no self-serve sign-up.
- This is an unofficial project, not affiliated with or endorsed by Badlands Media. All episodes and artwork remain the property of their owners.

## License

GPL-2.0-or-later (see the plugin header).
