# Authorize mcmanager's restricted Windows Service to install Minecraft
# under one dedicated local directory. No credentials, no service account
# elevation, and no recursive ACL changes to unrelated existing files.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Path,
    [ValidatePattern('^[A-Za-z0-9-]{3,60}$')][string]$ServiceName='mcmanager-worker'
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$principal=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run in an elevated PowerShell session to grant the local worker permission.'
}
$service=Get-CimInstance Win32_Service -Filter "Name='$ServiceName'"
if(-not $service -or $service.StartName -ne 'NT AUTHORITY\LocalService') {
    throw 'A local mcmanager Windows Service running as LocalService is required.'
}
if (-not [IO.Path]::IsPathRooted($Path) -or $Path.StartsWith('\\') -or
    $Path -notmatch '^[A-Za-z]:[\\/]') {
    throw 'Select an absolute local Windows folder, not a UNC/network folder.'
}
$parts=@(($Path -replace '/','\').Split('\'))
foreach($component in $parts) {
    if($component -eq '..' -or $component -eq '.') {
        throw 'Relative traversal and dot path components are not permitted.'
    }
}
$folder=[IO.Path]::GetFullPath($Path)
if($folder.TrimEnd('\') -eq [IO.Path]::GetPathRoot($folder).TrimEnd('\')) {
    throw 'Never grant worker permissions on an entire drive.'
}
if(-not (Test-Path -LiteralPath $folder -PathType Container)) {
    throw 'Create the dedicated Minecraft parent directory first, then grant access.'
}
$resolved=(Resolve-Path -LiteralPath $folder -ErrorAction Stop).ProviderPath
if(-not $resolved.TrimEnd('\').Equals($folder.TrimEnd('\'),[StringComparison]::OrdinalIgnoreCase)) {
    throw 'Directory aliases or symlinks are not permitted; choose its real path.'
}
$ancestors=$folder
while($ancestors -and $ancestors.Length -gt 3) {
    $item=Get-Item -LiteralPath $ancestors -Force -ErrorAction Stop
    if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Junction or symlink in selected folder: $ancestors"
    }
    $up=[IO.Directory]::GetParent($ancestors)
    if($null -eq $up) {break}
    $ancestors=$up.FullName
}
foreach($var in @('WINDIR','ProgramFiles','ProgramFiles(x86)')) {
    $systemFolder=[Environment]::GetEnvironmentVariable($var)
    if($systemFolder -and ($folder.Equals($systemFolder,[StringComparison]::OrdinalIgnoreCase) -or
        $folder.StartsWith($systemFolder.TrimEnd('\')+'\',[StringComparison]::OrdinalIgnoreCase))) {
        throw 'Windows system and application directories may not be used for Minecraft worlds.'
    }
}
$name=Split-Path -Path $folder -Leaf
if($name -notmatch '(?i)(minecraft|mcmanager|mc[-_]?servers?)') {
    throw 'Use a dedicated directory named Minecraft, MinecraftServers, mcmanager or mc-servers, not a shared folder containing unrelated files.'
}
# No /T: do not change ACLs on existing unrelated child content. The
# inheritable ACE applies to future managed instance-ID subfolders.
& icacls.exe $folder /grant '*S-1-5-19:(OI)(CI)M' | Out-Null
if($LASTEXITCODE -ne 0){throw 'Could not grant LocalService Modify access to the selected Minecraft folder.'}
Write-Output "PASS Local mcmanager service '$ServiceName' can create managed Minecraft instances in $folder"
Write-Output 'Only the selected directory ACL was modified; other folders and files were untouched.'
