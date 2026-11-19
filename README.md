# mcconfig for Synology - historical source

Recovered from the original mcconfig 0.1 and 0.2 Synology SPK packages published on arden.ie (2014). Version 0.2 is the primary working baseline.

mcconfig is a PHP web interface for managing Minecraft/CraftBukkit servers on historic Synology NAS devices, covering server properties, worlds/maps, logs, operators, bans, whitelists and server version updates.

## Layout

- current/source/ : fully extracted v0.2 application, PHP, JavaScript, CSS and assets
- current/spk/ : v0.2 Synology INFO, original LICENSE, icons and scripts
- history/v0.1/source/ and history/v0.1/spk/ : fully extracted original v0.1 for comparison
- ARCHIVE-MANIFEST.json : original SPK hashes, sizes and extraction counts
- original-spk/ : gitignored, local-only v0.1/v0.2 original SPK archives

## Compatibility and licences

This is archival DSM 5-era code, NOT a tested DSM 7/PHP 8.5 release. Before modernisation review filesystem permissions, uploaded content, remote JAR downloads, web authentication, and security.

Bundled JavaScript frameworks and the Java uploader JAR are historical third-party components with potentially separate licences; no blanket licence has been applied. The original SPK LICENSE remains in each version's spk/ folder.

## Historical site

- https://www.arden.ie/2014/04/01/mcconfig-minecraft-config-for-synology-systems/
- https://www.arden.ie/2014/04/30/mcconfig-version-0-2-ready-for-download/
- https://www.arden.ie/downloads/

## Next work

Inventory dependencies and update or replace unsafe libraries; plan modern DSM 7 packaging and test against disposable Minecraft/CraftBukkit installations.
