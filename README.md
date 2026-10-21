

> [!NOTE]
> #### About this fork
> This is [davidcoulson/NUT-unRAID](https://github.com/davidcoulson/NUT-unRAID), a fork of [desertwitch/NUT-unRAID](https://github.com/desertwitch/NUT-unRAID) that is maintained beyond upstream's end of support. Install it from the plugin URL:
> `https://raw.githubusercontent.com/davidcoulson/NUT-unRAID/master/plugin/nut-dw.plg`

## What's new in this fork

**Safety and security**
- GUI settings, including passwords with special characters, are written into the NUT config files without `sed`, so they can no longer corrupt the configs or run commands.
- The config editor can only read and write files in `/etc/nut`. Saves to flash are atomic, and a failed save is reported.
- Values reported by the UPS are escaped before they are shown in the UI.
- New installs get a random upsd admin password instead of `adminpass`.
- The command runner only accepts commands that the UPS itself lists.

**Reliable shutdown with several UPSes**
- Only the UPS selected in the GUI can start a shutdown. The others are monitored and send notifications.
- When one UPS comes back online, a shutdown pending for another UPS is no longer cancelled.
- If the UPS stops reporting data while on battery, the shutdown goes ahead after 120 seconds instead of waiting forever.
- Drivers that fail to start are retried, and you get an alert if the driver for the UPS that controls shutdown is not running.
- Service start, stop and config writes no longer overlap, and NUT is started again if a plugin upgrade fails.

**Monitoring and alerts**
- Alerts are on by default for low battery, forced shutdown, lost and restored communication, and system shutdown.
- An optional delay keeps short power blips from sending alerts, and an `EARLY_STAGE` hook runs after N seconds on battery (upssched).
- An "All UPS devices" overview on the details page, plus a per-UPS line on the dashboard tile.
- Scheduled battery self-tests (weekly or monthly, quick or deep) send their result as a notification.

**Control**
- Writable UPS settings (`upsrw`), such as battery thresholds and the beeper, can be edited from the Commands page for any UPS.
- Outlets can be switched on, off or power-cycled, with a confirmation step, on UPSes that report switchable outlets.
- New GUI settings: NOCOMMWARNTIME, FINALDELAY, HOSTSYNC and RBWARNTIME, plus a connection test in slave mode.

**Speed and UI**
- Status refreshes take about 0.02 s instead of 0.6 s on busy servers. The footer, dashboard tile and details page share one cached reading and pause polling while the browser tab is hidden.
- The settings page is grouped into collapsible sections, with inline validation, screen-reader labels, status shown as text as well as colour, layouts that fit a phone, and an editor that follows the light/dark theme.

**Building NUT**
- `build/build-nut.sh` builds the NUT Slackware packages in a throwaway container, using the same configure options as upstream. New NUT releases no longer depend on upstream to build them. See the comments at the top of the script for usage.

See the changelog in `plugin/nut-dw.plg` for details of each release.

---

> [!WARNING]
> #### Upstream Plugin Advisory: End of Support on 30.11.2026
> With new details on Unraid 8 now available, it felt like the right time to make a final decision about this plugin and set clear expectations. I've been focused on new things for a while and am preparing the transition of my own infrastructure, so I can no longer give the plugin the attention and love it deserves. It wasn't an easy choice, but support will end on 30.11.2026. From that date, the plugin stays installable as-is, but without any guarantee of stability or usability, so use is entirely at your own risk. The repository will be archived, but if a future Unraid release is reported to break the plugin in the support thread, I'll have to cap the maximum supported version and prevent further installs from that version onwards. Forks are very welcome; just coordinate with the Community Applications maintainers. Thank you all for the support over the years - Unraid has been an amazing community to serve, and I hope to stay in touch with many of you on the forums or Discord.


Network UPS Tools (NUT) for UNRAID
================
_A refactored, modernized version of the original NUT plugin with a focus on frequent updates._

![GitHub Tag](https://img.shields.io/github/v/tag/desertwitch/NUT-unRAID?label=release&color=peru)
[![CodeFactor](https://www.codefactor.io/repository/github/desertwitch/nut-unraid/badge)](https://www.codefactor.io/repository/github/desertwitch/nut-unraid)

The NUT plugin for UNRAID has been developed by various people since 2015.

This fork is my personal interest to modernize and streamline the existing code base and its dependencies.

My vision for this plugin, which basically acts as a frontend to NUT, can be summed up as follows:

* Debloated code with a consistent structure, permissioning and packaging throughout the project.

* Frequent updates of the NUT backend with fresh builds from the official master branch for the lastest changes.

* Frequent updates of the NUT frontend with meaningful changes improving both stability and ease of use.

This project continues to be licensed under GPL2 and/or GPL3 - see LICENSE files for information!

<sub>Network UPS Tools Source Code: https://github.com/networkupstools/nut</sub>
