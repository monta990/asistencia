param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$AppPoolName,
    [string]$DataPath = 'C:\ProgramData\CYAlimentos\ZKTimeNet'
)

$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path $DataPath | Out-Null
$identity = "IIS AppPool\$AppPoolName"
& icacls.exe $DataPath /grant "${identity}:(OI)(CI)M"
if ($LASTEXITCODE -ne 0) {
    throw "No se pudo asignar Modify a $identity sobre $DataPath"
}
Write-Host "Directorio de datos preparado: $DataPath"
Write-Host "Identidad autorizada: $identity"
