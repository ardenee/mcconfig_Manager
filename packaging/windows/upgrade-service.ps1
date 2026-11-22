# Update an installed mcmanager Windows worker service without touching worlds.
[CmdletBinding()]
param([ValidatePattern('^[A-Za-z0-9-]{3,60}$')][string]$ServiceName='mcmanager-worker')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$principal=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){
    throw 'Run the mcmanager service updater in an elevated PowerShell session.'
}
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$svc=Get-CimInstance Win32_Service -Filter "Name='$ServiceName'"
if(-not $svc){throw 'Windows mcmanager service is not installed.'}
if($svc.StartName -ne 'NT AUTHORITY\LocalService'){
    throw 'The existing Windows service account is not LocalService. No changes were made.'
}
$dir=Join-Path $env:ProgramData "mcmanager\services\$ServiceName"
$exe=Join-Path $dir 'McManagerService.exe'
$ini=Join-Path $dir 'worker.ini'
$app=Join-Path $dir 'app'
if(-not (Test-Path $ini -PathType Leaf) -or -not (Test-Path $exe -PathType Leaf) -or
    -not (Test-Path $app -PathType Container)){
    throw 'An existing managed Windows service installation was not found.'
}
$settings=@{}
Get-Content -LiteralPath $ini | ForEach-Object {
    if($_ -match '^([a-z]+)=(.*)$'){ $settings[$matches[1]]=$matches[2] }
}
if($settings['service'] -ne $ServiceName -or
    [IO.Path]::GetFullPath($settings['worker']) -ne [IO.Path]::GetFullPath((Join-Path $app 'agent\worker.php'))){
    throw 'Service worker path does not match the managed application directory.'
}
$compiler=Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if(-not (Test-Path $compiler)){$compiler=Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe'}
if(-not (Test-Path $compiler)){throw 'The .NET Framework 4.x compiler is missing.'}
$stage=Join-Path $dir ("app.next-"+$PID)
$previous=Join-Path $dir 'app.previous'
$previousExe=Join-Path $dir 'McManagerService.previous.exe'
if((Test-Path $stage) -or (Test-Path $previous) -or (Test-Path $previousExe)){
    throw 'A previous upgrade stage or rollback remains. Inspect it before another upgrade.'
}
New-Item -ItemType Directory -Path $stage -Force | Out-Null
try {
    foreach($folder in @('agent','src','public')){
        $src=Join-Path $root $folder
        $bad=@(Get-ChildItem -LiteralPath $src -Recurse -File | Where-Object {
            $_.Extension -notin @('.php','.js','.css','.png') -and $_.Name -ne '.htaccess'
        })
        if($bad.Count){throw "Unrecognized/possibly private deployable file in $folder"}
        Copy-Item -LiteralPath $src -Destination (Join-Path $stage $folder) -Recurse
    }
    New-Item -ItemType Directory -Path (Join-Path $stage 'var') | Out-Null
    foreach($file in @('.htaccess','index.php')){
        Copy-Item -LiteralPath (Join-Path $root ('var\'+$file)) -Destination (Join-Path $stage 'var')
    }
    $newExe=Join-Path $stage 'McManagerService.exe'
    & $compiler /nologo /target:winexe "/out:$newExe" /reference:System.ServiceProcess.dll (Join-Path $PSScriptRoot 'McManagerService.cs')
    if($LASTEXITCODE -ne 0 -or -not (Test-Path $newExe)){
        throw 'New Windows Service executable could not be compiled.'
    }
    Write-Output "Stopping local Windows service $ServiceName gracefully..."
    Stop-Service -Name $ServiceName -ErrorAction Stop
    (Get-Service $ServiceName).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(120))
    # Native Minecraft supervisors have saved their worlds. The worker
    # configuration still refers to /app, which is replaced atomically.
    Rename-Item -LiteralPath $app -NewName 'app.previous'
    Rename-Item -LiteralPath $stage -NewName 'app'
    Move-Item -LiteralPath $exe -Destination $previousExe
    Move-Item -LiteralPath (Join-Path $app 'McManagerService.exe') -Destination $exe
    try {
        Start-Service -Name $ServiceName -ErrorAction Stop
        (Get-Service $ServiceName).WaitForStatus('Running',[TimeSpan]::FromSeconds(35))
        Start-Sleep -Seconds 2
        if((Get-Service $ServiceName).Status -ne 'Running'){throw 'Service did not stay running.'}
    } catch {
        Write-Warning 'New service failed: reverting to the previous executable and application.'
        try {Stop-Service -Name $ServiceName -ErrorAction SilentlyContinue}catch{}
        if(Test-Path $exe){Remove-Item -LiteralPath $exe -Force}
        if(Test-Path $previousExe){Move-Item -LiteralPath $previousExe -Destination $exe}
        if(Test-Path $app){Rename-Item -LiteralPath $app -NewName ('app.failed-'+$PID)}
        if(Test-Path $previous){Rename-Item -LiteralPath $previous -NewName 'app'}
        Start-Service -Name $ServiceName -ErrorAction SilentlyContinue
        throw
    }
    Remove-Item -LiteralPath $previousExe -Force
    Remove-Item -LiteralPath $previous -Recurse -Force
    Write-Output "PASS Windows mcmanager worker service upgraded and running: $ServiceName"
    Write-Output 'Existing private configuration and all Minecraft/world directories were retained.'
} finally {
    if(Test-Path $stage){Remove-Item -LiteralPath $stage -Recurse -Force}
}
