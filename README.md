# mcmanager

**mcmanager** is a lightweight Minecraft Java and Bedrock server manager for Synology DSM and other PHP/Linux installations. It retains the original **mcconfig** icon and existing Synology package identifier so installed mcconfig packages can be upgraded in place.

The generic PHP application is the **primary distribution**. The optional DSM 7.2+ SPK bundles the same application together with a PHP CLI management worker. No database or third-party PHP framework is needed.

## What it does

- **Multiple servers:** independent Java (Vanilla, Paper, Spigot, CraftBukkit, Fabric, Forge, NeoForge, Purpur and Folia) and Bedrock profiles.
- **Native or Docker:** adopt existing installations with host-mounted Minecraft data. For new servers, the installer supports native Java **Vanilla and Paper**, or supported Java and Bedrock images through Docker/Container Manager.
- **Server lifecycle:** start, stop, restart, status, guarded uninstall and independent Linux native-server supervision. Native servers can survive a PHP management-worker restart and can recover their saved desired running state after reboot. Imported Java processes can use localhost-only RCON when configured. Imported containers retain external ownership.
- **Monitoring and console:** host architecture, RAM, disk, Java versions, Docker and server health, CPU/memory metrics and player counts. Detailed Container Manager/Docker CLI/socket ownership diagnostics help distinguish a running DSM package from actual Docker daemon authorization. Management job results refresh every 3 seconds while queued/running. Per-server console supports supervised native stdin, Java container RCON, Bedrock container send-command and localhost-only RCON for externally started Java.
- **Updates:** official Mojang and stable PaperMC version lookup; SHA-verified JAR downloads for native Vanilla/Paper, with backup. Container image refresh and recreation are administrator-initiated and restricted to manager-created containers.
- **Java management:** detect installed Java and install side-by-side Eclipse Temurin JRE **17, 21 or 25** via official latest-release dropdown, official HTTPS archive URL, or manual .tar.gz upload. Every method verifies the upstream SHA-256 and Linux ELF x64/ARM64 architecture. DSM system Java is left untouched.
- **Plugins:** per-server Bukkit/Paper-compatible plugin inventory, install by uploaded JAR or direct Modrinth CDN URL plus SHA-256, and remove while retaining a private recovery copy. The server must be stopped; Minecraft loads plugins on restart. Fabric/Forge mods and Bedrock add-ons need separate installers.
- **Existing mcconfig features:** edit server.properties preserving comments, manage Java ops/whitelist/bans (including UUID JSON), Bedrock allowlist/XUID permissions, supported Bukkit/Spigot/Paper YAML, import/export worlds, view file logs and upload a server JAR.
- **Recovery and safety:** administrator login and CSRF; HMAC-authenticated queue; no arbitrary shell controls; file backups and verified downloads. Queue cancellation is safe only before execution. Interrupted jobs are marked failed without destructive automatic replay; interrupted restore swaps recover the original server directory.
- **Backup and restoration:** independent SHA-256-verified ZIP snapshots, full world and configuration restoration through a staged folder swap, a pre-restore safety backup, retention controls (3–30) and preservation of executable file permissions. Restore requires the server to be stopped.

Minecraft itself is not bundled.

## Requirements

**Web interface:** PHP 8.2 or newer with session, zip and zlib, writable private application storage, and read/write access to Minecraft directories. Large uploads depend on Web Station's PHP profile and HTTP server limits; the DSM package requests 512M upload / 520M POST settings, but existing profiles and front-end limits must be checked.

**Optional local worker:** PHP 8.2+ CLI with zip, zlib and proc_open; Linux native persistence additionally requires util-linux setsid -f. OS support for Java/native processes and Docker CLI/daemon access are needed for container control. Docker is not required to manage native servers. A Docker-enabled worker can effectively administer host containers; grant access deliberately and only on a trusted NAS. Shared web hosting without a persistent CLI process still supports the file-management features.

**Hardware:** actual Java, CPU architecture, available RAM and disk space are checked before managed installations. Bedrock Linux images require supported x86-64 hardware. A managed Java installation uses the host's existing Java or a compatible managed Temurin runtime; containers provide their own Java.

## Generic web hosting installation

1. Build the primary ZIP: python packaging/build.py --format web
2. Extract dist/mcmanager-web-0.5.1.zip and set the web host document root to **public/**. Never expose src/, agent/ or var/.
3. Ensure the web process can write to a **private** var/ or set MCCONFIG_DATA_DIR to an external directory.
4. Open the site and create the administrator password. Use **Settings > Add server** for existing local Minecraft data, or **Server Manager** for discovery/install/lifecycle.

Optional management worker on a Linux machine with PHP CLI:

    MCCONFIG_DATA_DIR=/path/to/private/data \
    MCMANAGER_INSTANCES_DIR=/path/to/minecraft/instances \
    php agent/worker.php doctor

    MCCONFIG_DATA_DIR=/path/to/private/data \
    MCMANAGER_INSTANCES_DIR=/path/to/minecraft/instances \
    php agent/worker.php serve

Both processes must use identical private data and instances paths, with appropriate owner/ACL access. Keep the worker supervised by your service manager; avoid exposing it over HTTP. The once mode processes queued jobs for manual troubleshooting. On Linux, native servers use their own long-running supervisor and continue running across worker restarts. After a host reboot, the restarted worker attempts recovery of native servers whose signed desired state was running; retries are rate-limited. A normal worker or Package Center stop does not forcibly terminate a separate Minecraft supervisor. Stop Minecraft servers explicitly from the UI before uninstalling mcmanager.

## Synology DSM 7.2+

Install **Web Station** and **PHP 8.2** through Package Center, then build:

    python packaging/build.py --format all

This generates:

- dist/mcmanager-web-0.5.1.zip — generic web deployment
- dist/mcmanager-DSM7-0.5.1.spk — DSM Package Center manual install

The DSM package keeps internal package ID mcconfig and URL alias /mcconfig for upgrades. It registers the PHP 8.2 Web Station profile using backend **11** and enables session, zip and zlib. Package start invokes the private unprivileged PHP CLI worker, if its PHP runtime is available on the NAS.

By default the private application data lives in /var/packages/mcconfig/var; managed server files live below /var/packages/mcconfig/var/instances. For persistent Minecraft worlds on a volume, set MCMANAGER_INSTANCES_DIR for **both** PHP-FPM and the CLI worker before creating servers. DSM ACL permissions for the package service account are still needed. Container management requires access to DSM Container Manager's Docker service and is **not automatically granted**.

DSM Package Center lifecycle, Web Station registration, Docker access, setsid availability and native-worker permissions should be verified on the actual NAS before relying on production installation or upgrades. Persistent native supervision requires setsid -f; without it native launch is disabled rather than silently falling back to a fragile in-process child. DSM uninstall refuses to proceed while independent managed native Minecraft supervisors are running; stop those Minecraft servers from the dashboard before uninstalling.

## Example: existing Docker server

For an itzg/minecraft-server container mounted using:

    /volume1/docker/minecraft-java/data:/data

add the **NAS host** path /volume1/docker/minecraft-java/data, not /data, in Settings. Choose Java, Container, the server implementation, and the **existing container name**. Then choose **Adopt** in Server Manager. The agent verifies both the image and /data mount before offering lifecycle controls.

Bedrock containers use the equivalent host path and, where needed, a separate Bedrock worlds path. Docker environment variables may override changes to server.properties; with the itzg Java image, OVERRIDE_SERVER_PROPERTIES=false lets the properties file be managed independently when the chosen image supports this configuration.

## Important limitations

- **Imported servers are preserved.** The manager does not delete an imported container or its data. Existing native servers already running outside the worker cannot be sent process commands. Adopted servers can be edited and monitored; managed start/stop for native Java only works for processes started by the worker.
- **Installation support is edition-specific.** Automatic native installation is currently Vanilla/Paper Java. Bedrock and modded variants require supported Docker images or an existing installation; Java runtime installation is supported only on compatible Linux hosts.
- **Managed update is not automatic.** The administrator approves it. Native JAR installation failures attempt an immediate old-JAR/metadata rollback; container creation failures attempt the previous image. Backups are taken before changes, but world or plugin compatibility still requires administrator checks. Stop servers before consistent backups and software replacement.
- **Container permissions are powerful.** Do not expose Docker socket access to the PHP web process or give unrestricted root credentials to an HTTP endpoint. Running the CLI worker with Docker access requires an intentional NAS permissions decision.
- **Monitoring has scope limits.** CPU and RAM counters depend on host OS/permissions. The player count probe requires the local server protocol and port to respond. Container stdout/stderr is still viewed in DSM Container Manager.
- **Console is not a system terminal.** It accepts one Minecraft command at a time, never OS commands. Java containers require the itzg image's local RCON helper; Bedrock containers require its send-command helper. For imported native Java, RCON must be configured and reachable via 127.0.0.1. Native Bedrock outside the worker does not expose an equivalent RCON interface. Console output is a bounded recent-log view, not a persistent SSH/TTY session.
- **Plugin safety:** Plugin JARs execute arbitrary Java code inside Minecraft, not a sandbox. SHA-256 checks transfer integrity, not whether a plugin author is trustworthy. Plugins are not auto-updated, and dependencies/version compatibility are not automatically resolved. Install or remove plugins only while the server is stopped.
- **Java source restrictions:** Uploaded/downloaded JREs must match the current official Temurin release for the chosen major and NAS CPU. Unsupported/older archives are refused rather than installed without verified publisher metadata.
- **Docker access:** Synology Container Manager is the Docker package, but the mcconfig package account does not automatically have permission to access its daemon. Diagnostics show the CLI, socket ownership and worker identity. Granting Docker socket access is effectively host administration and is not automatically changed by the installer.
- **No remote server/SSH orchestration, scheduled auto-update, plugin marketplace or cross-host migrations** is currently implemented.
- **Backups:** v0.5.0 uses integrity-checked ZIP snapshots. Previous .tar.gz backup archives remain on disk but are not imported into the new restore interface. Snapshots require extra free disk space. Symlinks are rejected; imported Linux server roots with different ownership cannot be restored by the unprivileged worker. Synology ACLs are not preserved as part of a ZIP snapshot, so validate permissions before restoring external or shared-volume Minecraft data.
- **Uninstallation:** by default, managed server data is retained. Optional permanent deletion requires an exact name match and only applies to folders created/owned by mcmanager.

Project repository: https://github.com/ardenee/mcconfig
