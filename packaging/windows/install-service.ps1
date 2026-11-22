# mcmanager Windows Worker Service: compile in place, install as LocalService.
# Installs no third-party binaries, no Docker, no passwords.
[CmdletBinding()]
param(
    [ValidateSet('Check','Install','Remove')][string]$Mode='Check',
    [ValidatePattern('^[A-Za-z0-9-]{3,60}$')][string]$ServiceName='mcmanager-worker',
    [string]$PhpExe='',
    [string]$DataDir='',
    [string]$InstancesDir=''
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if(-not $PhpExe){$PhpExe=(Get-Command php.exe -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Source)}
if(-not $PhpExe){throw 'PHP CLI executable not found. Provide -PhpExe with an absolute local PHP 8.2+ path.'}
$PhpExe=[IO.Path]::GetFullPath($PhpExe)
$worker=Join-Path $root 'agent\worker.php'
if(-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)){throw "PHP CLI executable not found: $PhpExe"}
if(-not (Test-Path -LiteralPath $worker -PathType Leaf)){throw 'The packaged PHP management worker was not found.'}
if([string]::IsNullOrWhiteSpace($DataDir)){ $DataDir=Join-Path $env:ProgramData 'mcmanager\data' }
if([string]::IsNullOrWhiteSpace($InstancesDir)){ $InstancesDir=Join-Path $env:ProgramData 'mcmanager\instances' }
foreach($item in @($DataDir,$InstancesDir)){
    if(-not [IO.Path]::IsPathRooted($item) -or $item.StartsWith('\\')){throw 'Only absolute local Windows data paths are allowed.'}
}
$DataDir=[IO.Path]::GetFullPath($DataDir)
$InstancesDir=[IO.Path]::GetFullPath($InstancesDir)
# Granting recursive ACLs is a privileged operation. Reject arbitrary
# Windows/Program Files/user-profile directories or drive roots. Custom
# storage drives are supported only inside a dedicated mcmanager folder.
foreach($dir in @($DataDir,$InstancesDir)) {
    $segments=@($dir.TrimEnd('\').Split('\'))
    if(-not ($segments -contains 'mcmanager')){
        throw "Private Windows service storage must be inside a dedicated mcmanager folder: $dir"
    }
    $rootOfDir=[IO.Path]::GetPathRoot($dir)
    if($dir.TrimEnd('\') -eq $rootOfDir.TrimEnd('\')){
        throw 'Never grant service permissions on an entire Windows drive.'
    }
}
if($DataDir.TrimEnd('\').Equals($InstancesDir.TrimEnd('\'),[StringComparison]::OrdinalIgnoreCase)){
    throw 'Minecraft world storage and private worker configuration must use different directories.'
}
$serviceDir=Join-Path $env:ProgramData "mcmanager\services\$ServiceName"
$appDir=Join-Path $serviceDir 'app'
$serviceExe=Join-Path $serviceDir 'McManagerService.exe'
$serviceConfig=Join-Path $serviceDir 'worker.ini'
$compiler=Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if(-not (Test-Path $compiler)){ $compiler=Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe' }
if(-not (Test-Path $compiler)){throw '.NET Framework 4.x C# compiler not installed.'}
$phpInfo=(& $PhpExe -r 'echo PHP_VERSION;')
if($LASTEXITCODE -ne 0 -or [version]$phpInfo -lt [version]'8.2.0'){throw 'Windows mcmanager requires PHP CLI 8.2 or later.'}
$installed=Get-CimInstance Win32_Service -Filter "Name='$ServiceName'" -ErrorAction SilentlyContinue
Write-Output "Platform: Windows; PHP $phpInfo"
Write-Output "Windows service: $ServiceName"
Write-Output "Application: $root"
Write-Output "Private data: $DataDir"
Write-Output "Managed instances: $InstancesDir"
Write-Output "Existing Apache/IIS hosting is independent of the worker service."
if($Mode -eq 'Check'){
    Write-Output ("Service installed: " + [bool]$installed)
    Write-Output 'Preflight completed. Run -Mode Install as Administrator for a new service.'
    exit 0
}
$principal=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){
    throw 'Run Windows service installation/removal in an elevated PowerShell session.'
}
if($Mode -eq 'Remove'){
    if(-not $installed){Write-Output 'No service installed; all Minecraft data remains untouched.';exit 0}
    & sc.exe stop $ServiceName | Out-Null
    Start-Sleep -Seconds 2
    & sc.exe delete $ServiceName | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Could not remove the Windows service.'}
    Write-Output 'Windows service removed. Application and all Minecraft instance/world data preserved.'
    exit 0
}
if($installed){throw 'A service with that name already exists. No existing service was overwritten.'}
foreach($dir in @($serviceDir,$DataDir,$InstancesDir)){
    if(-not (Test-Path -LiteralPath $dir)){New-Item -ItemType Directory -Path $dir -Force | Out-Null}
}
# A Windows Service running as LocalService must not rely on access to its
# installer's user-profile source checkout. Stage only runtime app folders;
# never copy tests, git history or private configuration into ProgramData.
if (Test-Path -LiteralPath $appDir) {
    throw 'The target service application directory already exists. Refusing to overwrite code without an explicit upgrade.'
}
# Build the application under a separate staging name first. If a source
# validation or copy fails, no partially installed /app directory remains.
$stage=Join-Path $serviceDir ('app.stage-'+$PID)
if(Test-Path $stage){throw 'Unexpected existing Windows application staging path.'}
New-Item -ItemType Directory -Path $stage -Force | Out-Null
try {
    foreach($folder in @('agent','src','public')){
        $source=Join-Path $root $folder
        if(-not (Test-Path -LiteralPath $source -PathType Container)){
            throw "Required application folder missing: $source"
        }
        $bad=@(Get-ChildItem -LiteralPath $source -Recurse -File | Where-Object {
            $_.Extension -notin @('.php','.js','.css','.png') -and $_.Name -ne '.htaccess'
        })
        if($bad.Count){throw "Unexpected or potentially private files in application folder $folder"}
        Copy-Item -LiteralPath $source -Destination (Join-Path $stage $folder) -Recurse
    }
    # Only var access-denial stubs. Never ship HMAC keys, session data or configs.
    $varDir=Join-Path $stage 'var'
    New-Item -ItemType Directory -Path $varDir -Force | Out-Null
    foreach($name in @('.htaccess','index.php')) {
        Copy-Item -LiteralPath (Join-Path $root ('var\'+$name)) -Destination (Join-Path $varDir $name)
    }
    if(-not (Test-Path (Join-Path $stage 'agent\worker.php') -PathType Leaf)){
        throw 'Worker missing from validated Windows application stage.'
    }
    Rename-Item -LiteralPath $stage -NewName 'app'
} finally {
    if(Test-Path -LiteralPath $stage){Remove-Item -LiteralPath $stage -Recurse -Force}
}
$worker=Join-Path $appDir 'agent\worker.php'
# LocalService (SID S-1-5-19) gets the minimum data rights to operate the
# worker. Apache/IIS must separately have appropriate read/write access.
foreach($dir in @($DataDir,$InstancesDir)){
    & icacls.exe $dir /grant '*S-1-5-19:(OI)(CI)M' /T | Out-Null
    if($LASTEXITCODE -ne 0){throw "Could not grant LocalService private data access: $dir"}
}
& icacls.exe $serviceDir /grant '*S-1-5-19:(OI)(CI)RX' /T | Out-Null
if($LASTEXITCODE -ne 0){throw 'Could not grant LocalService service executable access.'}
if(-not (Test-Path -LiteralPath $worker -PathType Leaf)){
    throw 'Staged worker PHP entry point is missing.'
}
$source=Join-Path $PSScriptRoot 'McManagerService.cs'
& $compiler /nologo /target:winexe "/out:$serviceExe" /reference:System.ServiceProcess.dll $source
if($LASTEXITCODE -ne 0 -or -not (Test-Path $serviceExe)){throw 'Compiling the native Windows service failed.'}
# The ini contains paths only, never administrator passwords.
@(
    "service=$ServiceName",
    "php=$PhpExe",
    "worker=$worker",
    "data=$DataDir",
    "instances=$InstancesDir"
) | Set-Content -LiteralPath $serviceConfig -Encoding ASCII
$binPath='"'+$serviceExe+'" --config "'+$serviceConfig+'"'
& sc.exe create $ServiceName binPath= $binPath type= own start= auto obj= 'NT AUTHORITY\LocalService' DisplayName= 'mcmanager Minecraft Worker' | Out-Null
if($LASTEXITCODE -ne 0){throw 'Windows service registration failed.'}
& sc.exe config $ServiceName start= delayed-auto | Out-Null
& sc.exe failure $ServiceName reset= 86400 actions= 'restart/10000/restart/30000/restart/60000' | Out-Null
& sc.exe start $ServiceName | Out-Null
if($LASTEXITCODE -ne 0){throw 'Windows service registered but could not start. Inspect Event Viewer and windows-service.log.'}
Start-Sleep -Seconds 3
$status=Get-Service -Name $ServiceName
if($status.Status -ne 'Running'){throw 'Windows worker service did not remain running.'}
Write-Output "PASS Service installed and running as LocalService: $ServiceName"
Write-Output 'The web interface must be hosted by an independently managed Apache or IIS service.'
