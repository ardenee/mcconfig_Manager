# mcconfig Manager

**mcconfig Manager** is a web-based manager for Minecraft Java Edition and Bedrock Dedicated Servers running on the **same Windows PC, Linux machine or Synology NAS** as mcconfig Manager. It provides a single interface to install, configure, monitor and control supported local Minecraft servers.

The project retains the original **mcconfig** Synology package identifier for upgrade compatibility. The GitHub repository is **[ardenee/mcconfig_Manager](https://github.com/ardenee/mcconfig_Manager)**. The application is written in PHP and does not require a database.

## Features

- **Multiple servers:** Manage independent Minecraft installations and check their status from one dashboard.
- **Server installation:** Install supported Java or Bedrock server software in separate, managed folders.
- **Start, stop and restart:** Control servers, inspect logs and send supported Minecraft console commands.
- **Java runtimes:** Detect installed Java or install compatible Eclipse Temurin Java 17, 21 and 25 runtimes.
- **Configuration:** Edit server properties, ports, memory limits and supported JVM options.
- **Worlds and backups:** Manage server files and worlds; create, verify and restore ZIP backups.
- **Java plugins:** Browse and manage plugin files for compatible Java servers.
- **Custom storage:** Install servers under a chosen local folder or drive on Windows and Linux.
- **Existing installations:** Scan local directories for Java/Bedrock servers and optionally register them without moving their files.
- **Diagnostics:** Inspect CPU, RAM, storage and Java; use separate one-time authorization for DSM Container Manager checks.
- **Recovery:** Supervise native servers and recover supported running states after service or host restarts.

mcconfig Manager manages **servers on its own host only**. Windows, Linux and DSM each have their own platform-specific module; it does not control servers on other computers.

## Supported platforms

| Host operating system | Java Edition | Bedrock Edition | Deployment |
| --- | --- | --- | --- |
| **Windows (64-bit)** | Native Vanilla and Paper | Native Bedrock Dedicated Server | Local processes; Docker not needed |
| **Linux** | Native Vanilla and Paper | Native Bedrock on **Ubuntu 22.04+ x86-64** | Local processes; Docker not needed |
| **Synology DSM 7.2+** | Native Vanilla and Paper | Bedrock through **Container Manager** | DSM package and local worker |

Java servers need a compatible Java runtime; **Bedrock does not require Java**. Additional Java distributions such as Spigot, Fabric, Forge, NeoForge, Purpur and Folia can be registered or managed where supported, but are **not all automatically installed**.

**Windows** includes an optional Windows Service for worker startup, recovery and opt-in server restart following a reboot. **Linux** uses a local PHP worker that can be supervised by systemd. **DSM** uses Web Station. In **Server Manager → Create a Minecraft server**, the **Bedrock Edition** installer is displayed directly alongside a separate collapsible **Java Edition** installer. The Bedrock form is rendered by the server itself, even without JavaScript or Docker socket permissions. The wizard uses one-time DSM administrator authorization over HTTPS; credentials and DSM sessions are not saved.

## Requirements

- **PHP 8.2+** with required extensions including ZIP/zlib; PHP CLI and `proc_open` for server installation and control.
- Apache, IIS or Synology Web Station, serving the application's `public/` directory.
- A persistent **local PHP worker** for installation, process supervision and monitoring.
- Private, writable application storage and Minecraft folders accessible to the relevant local accounts.
- On Synology: Web Station and PHP 8.2+; Container Manager is required for Bedrock containers.
- Internet access to download Minecraft server binaries and managed Java runtimes.

The website can be used for some file-management functions without a worker, but full server control requires the local worker.

## Installation

### Windows and Linux

1. Build the generic web package with `python packaging/build.py --format web`, or obtain an existing package.
2. Extract `mcconfig_Manager-web-<version>.zip` and configure the web server document root as `public/`. Never expose `src/`, `agent/` or private data through the web server.
3. Set up private application storage. The web process and worker must share the same `MCCONFIG_DATA_DIR` and `MCMANAGER_INSTANCES_DIR` values, with appropriate permissions.
4. Open the web interface and create the administrator password.
5. Run the worker under a service manager: `php agent/worker.php serve` on Linux, or use `packaging/windows/install-service.ps1` for the Windows Service.
6. In **Server Manager**, install a new server or scan for a local existing installation.

On Windows, the service installer manages the worker, not Apache/IIS. For a custom installation drive, grant the restricted worker access to a dedicated folder before installing servers.

### Synology DSM

1. Install **Web Station** and **PHP 8.2+** in Package Center; install **Container Manager** for Bedrock.
2. Build the DSM package with `python packaging/build.py --format all`, or obtain a packaged SPK.
3. Install `mcconfig_Manager-DSM7-<version>.spk` using **Package Center → Manual Install**.
4. Open the app over HTTPS, set its administrator password and use Server Manager.

The package retains the internal name `mcconfig` and application address `/mcconfig/` for existing DSM installations.

**DSM package icon:** The original mcconfig 0.2 artwork is only 120×120 pixels. The SPK uses a high-quality reduction for its 64×64 icon and displays the unscaled original centered in a 256×256 transparent canvas, rather than enlarging and pixelating the old artwork. No higher-resolution original was present in the historical package.

## Limitations

- No remote management of Minecraft servers on other devices.
- Automatic native installation currently covers Java **Vanilla/Paper** and Bedrock on supported **Windows/Ubuntu** hosts; DSM Bedrock requires Container Manager.
- Imported servers retain their original files; available controls depend on their existing installation and startup method.
- Native Bedrock software updates are not yet automated. Current Bedrock NetherNet versions do not expose reliable player counts via the older UDP query.
- Minecraft client connectivity, firewall rules and third-party plugin compatibility require separate configuration and verification.

## License

[MIT](LICENSE)

Project website: [arden.ie](https://www.arden.ie/) · [GitHub](https://github.com/ardenee/mcconfig_Manager)
