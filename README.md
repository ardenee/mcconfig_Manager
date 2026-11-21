# mcconfig

mcconfig is a small, self-hosted PHP web interface for managing **Minecraft Java Edition** and **Minecraft Bedrock Dedicated Server** configuration. It works with traditional local installations or containerized servers using **host bind mounts**.

The primary distribution is a generic web application. An optional Synology **DSM 7.2+ SPK** is generated from the exact same application sources. The original mcconfig icon is retained.

## Features

- Password-protected, responsive web interface with multiple server profiles.
- Dynamic editor for all properties in server.properties, preserving unknown keys and comments.
- Java Edition: operators, whitelist, player/IP bans, including modern UUID JSON lists and older text files.
- Bedrock Edition: allowlist by gamertag and permissions by Xbox XUID (operator/member/visitor).
- Bukkit, Spigot and Paper: edit supported configuration files, including modern config/paper-global.yml and config/paper-world-defaults.yml.
- Discover/select, ZIP-export and import worlds (separate Bedrock worlds paths supported).
- View recent file-based server logs; manually upload a replacement Java server JAR with a backup.
- Backups before configuration changes, CSRF protection, restricted file operations and checked world ZIP extraction.
- No database, PHP frameworks, Docker socket, Java upload applet or external program execution.

## Runtime

- PHP **8.2+**, sessions, **zip** and **zlib** enabled.
- A writable private application data directory and filesystem permissions on the Minecraft files.
- HTTPS and restricted network access are recommended for this administration application.

Minecraft is **not** included. mcconfig does not launch containers, install Java runtimes, run Minecraft commands or restart server processes. Server operation remains with DSM Container Manager, systemd, Docker Compose or your existing Minecraft package.

## Generic web install

    python packaging/build.py --format web

Extract the generated ZIP into the web host. Configure the document root to **public/**; **src/** and **var/** must not be publicly accessible. Allow the PHP account to write to **var/** or set the MCCONFIG_DATA_DIR environment variable to a private path.

Open the site, create an administrator password, then **Settings > Add server**. The server path must be the host's absolute directory containing server.properties. For Java installs, a JAR filename is optional. For Bedrock, specify the directory containing server.properties and a separate worlds directory if necessary.

## Synology DSM 7.2+

Install Web Station and PHP 8.2 from Package Center, then build:

    python packaging/build.py --format spk

The resulting SPK is installed using **Package Center > Manual Install**. It registers the /mcconfig Web Station portal and a dedicated PHP 8.2 profile using **backend 11**, with **session, zip and zlib** enabled. The application runs under an unprivileged account.

The profile's open_basedir permits common /volume1–/volume6 storage, but DSM ACL permissions still apply. Grant the **mcconfig PHP service user** access to only the Minecraft directories it needs. Configuration is kept under /var/packages/mcconfig/var, separately from deployable web files. The package does not need Init_3rdparty or DSM 5 Apache scripts.

## Container examples

**Java / Paper / Spigot / CraftBukkit:** A container such as [itzg/minecraft-server](https://github.com/itzg/docker-minecraft-server) commonly mounts:

    /volume1/docker/minecraft-java/data:/data

In mcconfig set **Edition: Java**, **Deployment: Container**, **Host path: /volume1/docker/minecraft-java/data**.

**Bedrock:** [itzg/minecraft-bedrock-server](https://github.com/itzg/docker-minecraft-bedrock-server) usually uses a host-mounted /data directory. If a setup uses separate configuration and world volumes, set both host paths, such as:

    /volume1/docker/minecraft-bedrock/config
    /volume1/docker/minecraft-bedrock/worlds

Set **Edition: Bedrock** and use those as the configuration and worlds paths.

**Important:** Docker variables may regenerate or override server.properties every time the container starts. For the itzg Java image, consider setting **OVERRIDE_SERVER_PROPERTIES=false** when managing that file directly, and avoid conflicting environment variables. Container stdout logs appear in **DSM Container Manager** unless also saved to a file.

## Limitations

- PHP needs host filesystem access; it cannot browse an unmounted container filesystem or another server over the network.
- All changes to local Minecraft files can be overwritten by server-generated settings or container environment variables; restart Minecraft as needed.
- Bedrock uses **Xbox XUIDs** and gamertags, not Java UUIDs. Bedrock has no Java JAR uploader. Server process / console control is intentionally outside mcconfig.
- World imports are non-overwriting and exports are not consistent live snapshots. Stop the server before world import/export and Java JAR replacement.
- Some Paper/plugin configuration files are version-dependent; the restricted editor supports known filenames, not arbitrary filesystem browsing.
- A Java server may require a newer Java runtime and substantial RAM/CPU despite minimal container overhead; check hardware and Minecraft version requirements.

Original DSM 5-era mcconfig source is retained in Git history and an offline development archive.
