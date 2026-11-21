# mcconfig

mcconfig is a lightweight PHP web interface for managing Minecraft Java Edition servers whose files are available on the web host. Originally built for Synology NAS, it now runs as a **generic PHP web application**. An optional **Synology DSM 7.2+ package** is built from the same files.

## Features

- Responsive administration interface using the original mcconfig artwork.
- Password-protected administrator account and CSRF-protected changes.
- Manage multiple local Minecraft, CraftBukkit, Spigot or Paper server directories.
- Edit all existing server.properties keys, including newer Minecraft options; add new keys without deleting unrelated settings or comments.
- Operators, whitelist, banned players and banned IPs. Supports modern JSON formats (Minecraft UUIDs required) and legacy text lists.
- Edit existing Bukkit/Spigot/Paper YAML configuration and EULA text files with file backups.
- Detect and select worlds, download a world ZIP and import a new world ZIP.
- View the recent server log without loading an entire large log file.
- Upload a replacement server JAR after stopping Minecraft; keep a backup of the prior JAR.
- No database, Node.js, Composer, Java uploader applet, shell execution or external runtime libraries.

## Requirements

- PHP **8.2 or later** with sessions enabled.
- PHP **zip** extension for world ZIP import/export.
- Read/write filesystem access for the specific Minecraft server files you want to edit.
- A private writable storage directory for application configuration.
- HTTPS and restricted access to the management URL.

mcconfig works with **local or mounted** Minecraft files. Ordinary shared hosting cannot manage Minecraft files hosted on another machine unless those files are made accessible to the PHP host. It does not start or stop the Minecraft process.

## Generic web installation

Run:

    python packaging/build.py --format web

Extract the generated mcconfig-web ZIP onto your PHP server, and configure the web server document root to its **public/** directory. Keep **src/** and **var/** outside the public document root. Ensure **var/** is writable by the PHP account, or configure an external directory using the MCCONFIG_DATA_DIR environment variable. The web ZIP does not contain any password or private configuration.

Open the website, create an administrator password, then use **Settings → Add a Minecraft server**. Enter the absolute path to the server directory containing server.properties. Set the JAR filename if you want manual JAR replacements.

For Apache, the included .htaccess blocks directory listings and requests for additional PHP files in public/. For nginx, set the document root to public/ and ensure only index.php is executable.

## Synology DSM 7.2+

Install **Web Station** and **PHP 8.2** (or a compatible newer PHP package). Build the optional SPK with:

    python packaging/build.py --format spk

Install the generated mcconfig-DSM7 SPK through Package Center → Manual Install. It registers a Web Station **/mcconfig** portal and a PHP profile without using old Init_3rdparty or DSM 5 Apache scripts. The original icon is preserved.

The DSM package stores configuration in /var/packages/mcconfig/var and serves the application from the web_packages directory. For server files under /volume1, /volume2, and so on, ensure that the **mcconfig** PHP service account has the required access. Review the PHP profile's open_basedir paths in Web Station if you use other volumes.

## Limits and precautions

- Configuration and player list changes may require a Minecraft restart or reload.
- The JAR uploader performs **manual local uploads only**. No downloads from untrusted update URLs.
- Stop Minecraft before replacing JARs or transferring worlds; world export is not a live consistent snapshot.
- World ZIP transfers are limited to 512 MiB uploads and 2 GiB uncompressed data. Existing worlds are never overwritten on import.
- This is a **file manager for supported Minecraft configuration**, not a remote console, service manager or full-featured Minecraft server panel.
- YAML files are edited as text, not semantically validated.
- Do not expose the first-run setup page on the public Internet before creating your administrator password.

The historical 2014 DSM 5-era implementation is preserved in earlier Git history, separate from the deployable application.
