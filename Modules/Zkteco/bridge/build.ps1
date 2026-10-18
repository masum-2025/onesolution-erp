# Builds zkteco-bridge.exe from ZktecoBridge.cs with the C# compiler of
# .NET Framework 4 that every Windows 10/11 has (nothing to install).
# The exe lands in Modules/Zkteco/bridge/dist and is what the app gives for download.
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path $csc)) { $csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe' }
$dist = Join-Path $here 'dist'
New-Item -ItemType Directory -Force $dist | Out-Null
& $csc /nologo /target:exe /platform:anycpu /optimize+ /out:"$dist\zkteco-bridge.exe" /reference:System.Web.Extensions.dll "$here\ZktecoBridge.cs"
if ($LASTEXITCODE -ne 0) { throw "csc failed ($LASTEXITCODE)" }
Get-Item "$dist\zkteco-bridge.exe" | Select-Object Name, Length
