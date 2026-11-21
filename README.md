# mcmanager

**mcmanager** is a lightweight Minecraft Java and Bedrock server manager for Synology DSM and other PHP/Linux installations. It retains the original **mcconfig** icon and existing Synology package identifier so installed mcconfig packages can be upgraded in place.

The generic PHP application is the **primary distribution**. The optional DSM 7.2+ SPK bundles the same application together with a PHP CLI management worker. No database or third-party PHP framework is needed.

## What it does

- **Multiple servers:** independent Java (Vanilla, Paper, Spigot, CraftBukkit, Fabric, Forge, NeoForge, Purpur and Folia) and Bedrock profiles.
- **Native or Docker:** adopt existing installations with host-mounted Minecraft data. For new servers, the installer supports native Java **Vanilla and Paper**, or supported Java and Bedrock images through Docker/Container Manager.
- **Server lifecycle:** start, stop, restart, status, backups and guarded uninstall for managed servers. Imported native processes are read-only unless started under the worker; imported containers require explicit validation and retain external ownership.
- **Monitoring:** host architecture, RAM, disk, installed Java versions, Docker availability, native/container process status, CPU and memory metrics where supported, and Java/Bedrock local player-count probes.
- **Updates:** official Mojang and stable PaperMC version lookup; SHA-verified JAR downloads for native Vanilla/Paper, with backup. Container image refresh and recreation are administrator-initiated and restricted to manager-created containers.
- **Java management:** detect installed Java and install side-by-side **Eclipse Temurin JRE 17, 21 or 25** on supported Linux hosts; check Minecraft/Java version compatibility. System Java packages are not replaced.
- **Existing mcconfig features:** edit server.properties preserving comments and other keys, manage Java ops/whitelist/bans (including UUID JSON), Bedrock allowlist and XUID permissions, edit supported Bukkit/Spigot/Paper YAML, import/export worlds, view file logs and upload a JAR.
- **Safety:** admin login, CSRF, private HMAC-authenticated job queue, restricted action/argument lists, verified software sources, installation-only deletion, file backups, and no public worker HTTP endpoint. Job status reports queued/running/completed/failed stages.

Minecraft itself is not bundled.

## Requirements

**Web interface:** PHP 8.2 or newer with session, zip and zlib, writable private application storage, and read/write access to the selected Minecraft directories.

**Optional local worker:** PHP 8.2+ CLI with proc_open, OS support for Java/native processes, and Docker CLI/daemon access for container control. Docker is not required to manage native servers. A Docker-enabled worker can effectively administer host containers; grant access deliberately and only on a trusted NAS. Shared web hosting without a persistent CLI process still supports the file-management features.

**Hardware:** actual Java, CPU architecture, available RAM and disk space are checked before managed installations. Bedrock Linux images require supported x86-64 hardware. A managed Java installation uses the host's existing Java or a compatible managed Temurin runtime; containers provide their own Java.

## Generic web hosting installation

1. Build the primary ZIP: python packaging/build.py --format web
2. Extract dist/mcmanager-web-0.4.0.zip and set the web host document root to **public/**. Never expose src/, agent/ or var/.
3. Ensure the web process can write to a **private** var/ or set MCCONFIG_DATA_DIR to an external directory.
4. Open the site and create the administrator password. Use **Settings > Add server** for existing local Minecraft data, or **Server Manager** for discovery/install/lifecycle.

Optional management worker on a Linux machine with PHP CLI:

    MCCONFIG_DATA_DIR=/path/to/private/data \
    MCMANAGER_INSTANCES_DIR=/path/to/minecraft/instances \
    php agent/worker.php doctor

    MCCONFIG_DATA_DIR=/path/to/private/data \
    MCMANAGER_INSTANCES_DIR=/path/to/minecraft/instances \
    php agent/worker.php serve

Both processes must use identical private data and instances paths, with appropriate owner/ACL access. Keep the worker supervised by your service manager; avoid exposing it over HTTP. The once mode processes queued jobs for manual troubleshooting but **cannot** keep native Minecraft processes alive persistently.

## Synology DSM 7.2+

Install **Web Station** and **PHP 8.2** through Package Center, then build:

    python packaging/build.py --format all

This generates:

- dist/mcmanager-web-0.4.0.zip — generic web deployment
- dist/mcmanager-DSM7-0.4.0.spk — DSM Package Center manual install

The DSM package keeps internal package ID mcconfig and URL alias /mcconfig for upgrades. It registers the PHP 8.2 Web Station profile using backend **11** and enables session, zip and zlib. Package start invokes the private unprivileged PHP CLI worker, if its PHP runtime is available on the NAS.

By default the private application data lives in /var/packages/mcconfig/var; managed server files live below /var/packages/mcconfig/var/instances. For persistent Minecraft worlds on a volume, set MCMANAGER_INSTANCES_DIR for **both** PHP-FPM and the CLI worker before creating servers. DSM ACL permissions for the package service account are still needed. Container management requires access to DSM Container Manager's Docker service and is **not automatically granted**.

DSM Package Center lifecycle, Web Station registration, Docker access and runtime paths should be verified on the actual NAS before relying on production installation or upgrades.

## Example: existing Docker server

For an itzg/minecraft-server container mounted using:

    /volume1/docker/minecraft-java/data:/data

add the **NAS host** path /volume1/docker/minecraft-java/data, not /data, in Settings. Choose Java, Container, the server implementation, and the **existing container name**. Then choose **Adopt** in Server Manager. The agent verifies both the image and /data mount before offering lifecycle controls.

Bedrock containers use the equivalent host path and, where needed, a separate Bedrock worlds path. Docker environment variables may override changes to server.properties; with the itzg Java image, OVERRIDE_SERVER_PROPERTIES=false lets the properties file be managed independently when the chosen image supports this configuration.

## Important limitations

- **Imported servers are preserved.** The manager does not delete an imported container or its data. Existing native servers already running outside the worker cannot be sent process commands. Adopted servers can be edited and monitored; managed start/stop for native Java only works for processes started by the worker.
- **Installation support is edition-specific.** Automatic native installation is currently Vanilla/Paper Java. Bedrock and modded variants require supported Docker images or an existing installation; Java runtime installation is supported only on compatible Linux hosts.
- **Managed update is not automatic.** The administrator approves it. Updates may alter plugin/mod/world compatibility; backups and image/JAR checks help but do not guarantee rollback of worlds. Stop servers before consistent backups and software replacement.
- **Container permissions are powerful.** Do not expose Docker socket access to the PHP web process or give unrestricted root credentials to an HTTP endpoint. Running the CLI worker with Docker access requires an intentional NAS permissions decision.
- **Monitoring has scope limits.** CPU and RAM counters depend on host OS/permissions. The player count probe requires the local server protocol and port to respond. Container stdout/stderr is still viewed in DSM Container Manager.
- **No remote server/SSH orchestration, scheduled auto-update, plugin marketplace, cross-host migrations or full RCON console** is currently implemented.
- **Uninstallation:** by default, managed server data is retained. Optional permanent deletion requires an exact name match and only applies to folders created/owned by mcmanager.

Project repository: https://github.com/ardenee/mcconfig
