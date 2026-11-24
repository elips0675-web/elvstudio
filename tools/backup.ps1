param(
    [Parameter(Position = 0)]
    [ValidateSet('backup', 'restore', 'list')]
    [string]$Action = 'list',

    [string]$Name = '',
    [string]$Note = '',

    [ValidateSet('theme', 'site')]
    [string]$Scope = 'theme',

    [switch]$Force,
    [switch]$SkipSafetyBackup
)

$ErrorActionPreference = 'Stop'

try {
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
}
catch {
    # Консоль не переключилась на UTF-8: сообщения будут в кодировке системы.
}

$Root = Split-Path -Parent $PSScriptRoot
$BackupsRoot = Join-Path $Root 'backup'
$ServerPort = 8090

$ThemePaths = @('themes\elv-studio', 'plugins\elv\kit')

$SiteExcludePrefixes = @(
    'backup\',
    '.git\',
    '.local\',
    'vendor\',
    'modules\',
    'node_modules\',
    'demo\',
    'demo-static\',
    'docs\',
    'exports\',
    'test\',
    'bootstrap\cache\',
    'storage\logs\',
    'storage\framework\cache\',
    'storage\framework\sessions\',
    'storage\framework\views\',
    'storage\framework\testing\',
    'storage\framework\temp\',
    'storage\debugbar\',
    'storage\temp\'
)

function Get-EnvValue {
    param([string]$Key, [string]$Default = '')

    $file = Join-Path $Root '.env'

    if (-not (Test-Path -LiteralPath $file)) {
        return $Default
    }

    foreach ($line in [IO.File]::ReadAllLines($file)) {
        if ($line -match ('^\s*' + [regex]::Escape($Key) + '\s*=\s*(.*)$')) {
            $value = $Matches[1].Trim()

            if ($value -match '^"(.*)"$' -or $value -match "^'(.*)'$") {
                $value = $Matches[1]
            }

            return $value
        }
    }

    return $Default
}

function Get-DbSettings {
    return [pscustomobject]@{
        Host     = Get-EnvValue 'DB_HOST' '127.0.0.1'
        Port     = Get-EnvValue 'DB_PORT' '3306'
        Database = Get-EnvValue 'DB_DATABASE'
        User     = Get-EnvValue 'DB_USERNAME' 'root'
        Password = Get-EnvValue 'DB_PASSWORD'
    }
}

function Find-MysqlTool {
    param([string]$Tool)

    $candidates = @()

    if ($env:MYSQL_BIN) {
        $candidates += (Join-Path $env:MYSQL_BIN ($Tool + '.exe'))
    }

    $candidates += Get-ChildItem -Path 'C:\laragon\bin\mysql' -Recurse -Filter ($Tool + '.exe') -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending |
        Select-Object -ExpandProperty FullName

    $found = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1

    if (-not $found) {
        throw "Не найден $Tool.exe. Укажите MYSQL_BIN или добавьте MySQL в PATH."
    }

    return $found
}

function Invoke-Mysql {
    param([string]$Executable, [string[]]$Arguments)

    $previous = $env:MYSQL_PWD
    $env:MYSQL_PWD = $Db.Password

    try {
        & $Executable @Arguments

        if ($LASTEXITCODE -ne 0) {
            throw "$Executable завершился с кодом $LASTEXITCODE."
        }
    }
    finally {
        $env:MYSQL_PWD = $previous
    }
}

function Invoke-MysqlScalar {
    param([string]$Executable, [string[]]$Arguments)

    $previous = $env:MYSQL_PWD
    $env:MYSQL_PWD = $Db.Password

    try {
        $output = & $Executable @Arguments

        if ($LASTEXITCODE -ne 0) {
            throw "$Executable завершился с кодом $LASTEXITCODE."
        }

        return (($output | ForEach-Object { "$_" }) -join '').Trim()
    }
    finally {
        $env:MYSQL_PWD = $previous
    }
}

function Test-Excluded {
    param([string]$RelativePath)

    foreach ($prefix in $SiteExcludePrefixes) {
        if ($RelativePath.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }

    return $false
}

function Test-InScope {
    param([string]$RelativePath)

    if ($Scope -eq 'theme') {
        foreach ($path in $ThemePaths) {
            if ($RelativePath.StartsWith($path + '\', [StringComparison]::OrdinalIgnoreCase)) {
                return $true
            }
        }

        return $false
    }

    return -not (Test-Excluded $RelativePath)
}

function New-Snapshot {
    param([string]$Suffix, [string]$SnapshotNote)

    if (-not $Suffix) {
        $Suffix = if ($Scope -eq 'site') { 'landing-site' } else { 'landing-theme-db' }
    }

    if ($Suffix -notmatch '^[A-Za-z0-9._-]+$') {
        throw 'Суффикс имени: только латиница, цифры, точка, дефис и подчёркивание.'
    }

    $folder = (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '_' + $Suffix
    $dir = Join-Path $BackupsRoot $folder

    while (Test-Path -LiteralPath $dir) {
        Start-Sleep -Seconds 1
        $folder = (Get-Date -Format 'yyyy-MM-dd_HH-mm') + '_' + $Suffix
        $dir = Join-Path $BackupsRoot $folder
    }

    if (-not $Db.Database) {
        throw 'В .env не указана DB_DATABASE.'
    }

    New-Item -ItemType Directory -Path $dir -Force | Out-Null

    $zipPath = Join-Path $dir 'files.zip'
    $dbPath = Join-Path $dir 'db.sql'

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $zip = [IO.Compression.ZipFile]::Open($zipPath, [IO.Compression.ZipArchiveMode]::Create)
    $fileCount = 0

    try {
        Get-ChildItem -LiteralPath $Root -Recurse -File -Force | ForEach-Object {
            $relative = $_.FullName.Substring($Root.Length + 1)

            if (-not (Test-InScope $relative)) {
                return
            }

            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip,
                $_.FullName,
                $relative,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null

            $fileCount++
        }
    }
    finally {
        $zip.Dispose()
    }

    $dump = Find-MysqlTool 'mysqldump'

    Invoke-Mysql $dump @(
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--default-character-set=utf8mb4',
        ('--host=' + $Db.Host),
        ('--port=' + $Db.Port),
        ('--user=' + $Db.User),
        '--databases',
        $Db.Database,
        ('--result-file=' + $dbPath)
    )

    $mysql = Find-MysqlTool 'mysql'
    $tables = Invoke-MysqlScalar $mysql @(
        ('--host=' + $Db.Host),
        ('--port=' + $Db.Port),
        ('--user=' + $Db.User),
        '-N',
        '-B',
        '-e',
        ("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" + $Db.Database + "';")
    )

    if ($tables -notmatch '^\d+$') {
        throw 'Не удалось прочитать число таблиц БД из information_schema.'
    }

    $head = ''
    try {
        $head = (git -C $Root rev-parse --short HEAD).Trim()
    }
    catch {
        $head = ''
    }

    $meta = [ordered]@{
        name         = $folder
        note         = $SnapshotNote
        scope        = $Scope
        created_at   = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        site         = $Root
        database     = $Db.Database
        db_tables    = [int]$tables
        db_size      = (Get-Item -LiteralPath $dbPath).Length
        files        = $fileCount
        files_size   = (Get-Item -LiteralPath $zipPath).Length
        git_head     = $head
        includes     = if ($Scope -eq 'site') { 'файлы сайта (без vendor, modules, node_modules, backup, docs, test, demo, demo-static, exports, кэшей) и БД' } else { 'themes/elv-studio, plugins/elv/kit и БД' }
        excludes     = if ($Scope -eq 'site') { 'vendor, modules, node_modules, .git, backup, docs, test, demo, demo-static, exports, storage/logs, storage/framework/{cache,sessions,views,temp,testing}, bootstrap/cache' } else { 'всё, кроме themes/elv-studio и plugins/elv/kit' }
    }

    [IO.File]::WriteAllText((Join-Path $dir 'meta.json'), ($meta | ConvertTo-Json -Depth 3), (New-Object System.Text.UTF8Encoding($false)))

    $description = @(
        'Бэкап витрины D:\Elv Landing',
        ('Папка: ' + $folder),
        ('Дата: ' + $meta.created_at),
        ('Режим: ' + $Scope + ' (' + $meta.includes + ')'),
        ('Git HEAD: ' + $head),
        ('БД: ' + $Db.Database + ' (db.sql, ' + [math]::Round($meta.db_size / 1KB, 1) + ' КБ, таблиц ' + $tables + ')'),
        ('Файлы: ' + $fileCount + ' (files.zip, ' + [math]::Round($meta.files_size / 1KB, 1) + ' КБ)'),
        '',
        'Восстановление:',
        ('  powershell -NoProfile -ExecutionPolicy Bypass -File tools\backup.ps1 restore -Name ' + $folder + ' -Force'),
        'Перед откатом автоматически снимается снимок pre-restore (если не указан -SkipSafetyBackup).',
        'Откат останавливает сервер на порту 8090, заменяет файлы режима и пересоздаёт БД.'
    ) -join [Environment]::NewLine

    [IO.File]::WriteAllText((Join-Path $dir 'description.txt'), $description + [Environment]::NewLine, (New-Object System.Text.UTF8Encoding($false)))

    $hashes = @()
    foreach ($file in @('files.zip', 'db.sql')) {
        $hash = (Get-FileHash -LiteralPath (Join-Path $dir $file) -Algorithm SHA256).Hash.ToLower()
        $hashes += ($hash + '  ' + $file)
    }
    [IO.File]::WriteAllLines((Join-Path $dir 'SHA256SUMS.txt'), $hashes, (New-Object System.Text.UTF8Encoding($false)))

    Write-Output ("Снимок «" + $folder + "» готов: файлов " + $fileCount +
        ", ZIP " + [math]::Round($meta.files_size / 1KB, 1) + " КБ, дамп " +
        [math]::Round($meta.db_size / 1KB, 1) + " КБ, БД " + $Db.Database + " (" + $tables + " таблиц)")
}

function Stop-LandingServer {
    try {
        $listeners = Get-NetTCPConnection -LocalPort $ServerPort -State Listen -ErrorAction SilentlyContinue
    }
    catch {
        $listeners = $null
    }

    if (-not $listeners) {
        return
    }

    foreach ($listener in $listeners) {
        Stop-Process -Id $listener.OwningProcess -Force -ErrorAction SilentlyContinue
        Write-Output ("Остановлен процесс " + $listener.OwningProcess + " на порту " + $ServerPort)
    }

    Start-Sleep -Seconds 1
}

function Clear-LandingCache {
    $php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'

    if (-not (Test-Path -LiteralPath $php)) {
        $command = Get-Command php -ErrorAction SilentlyContinue
        $php = if ($command) { $command.Source } else { '' }
    }

    if ($php -eq '') {
        return
    }

    try {
        & $php artisan optimize:clear 2>&1 | Out-Null
    }
    catch {
        # Кэш не критичен: сайт соберёт его заново.
    }
}

function Restore-Snapshot {
    param([string]$SnapshotName)

    if (-not $SnapshotName) {
        throw 'Укажите имя снимка: restore -Name <имя>.'
    }

    $dir = Join-Path $BackupsRoot $SnapshotName

    if (-not (Test-Path -LiteralPath $dir)) {
        $found = @(Get-ChildItem -LiteralPath $BackupsRoot -Directory -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -like ('*_' + $SnapshotName) })

        if ($found.Count -eq 1) {
            $dir = $found[0].FullName
            $SnapshotName = $found[0].Name
        }
        elseif ($found.Count -gt 1) {
            throw ('Несколько снимков с суффиксом «' + $SnapshotName + '»: ' +
                (($found | ForEach-Object { $_.Name }) -join ', '))
        }
    }

    $zipPath = Join-Path $dir 'files.zip'

    if (-not (Test-Path -LiteralPath $zipPath) -and (Test-Path -LiteralPath (Join-Path $dir 'theme.zip'))) {
        $zipPath = Join-Path $dir 'theme.zip'
    }

    $dbPath = Join-Path $dir 'db.sql'

    if (-not (Test-Path -LiteralPath $zipPath) -or -not (Test-Path -LiteralPath $dbPath)) {
        throw "В снимке «$SnapshotName» нет files.zip (или theme.zip) либо db.sql."
    }

    $metaPath = Join-Path $dir 'meta.json'
    $snapshotScope = 'theme'

    if (Test-Path -LiteralPath $metaPath) {
        try {
            $meta = Get-Content -LiteralPath $metaPath -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($meta.scope) {
                $snapshotScope = [string]$meta.scope
            }
        }
        catch {
            $snapshotScope = 'theme'
        }
    }

    if (-not $Force) {
        $answer = Read-Host ("Откатить витрину к «" + $SnapshotName + "»? Файлы режима " + $snapshotScope + " и БД будут перезаписаны. Введите yes")
        if ($answer -ne 'yes') {
            Write-Output 'Откат отменён.'
            return
        }
    }

    if (-not $SkipSafetyBackup) {
        $previousScope = $Scope
        $script:Scope = $snapshotScope

        try {
            New-Snapshot 'pre-restore' ("перед откатом к " + $SnapshotName) | Out-Null
        }
        finally {
            $script:Scope = $previousScope
        }
    }

    Stop-LandingServer

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $zip = [IO.Compression.ZipFile]::OpenRead($zipPath)
    $restoredCount = 0

    try {
        $targets = @()

        foreach ($entry in $zip.Entries) {
            if (-not $entry.Name) {
                continue
            }

            $entryPath = $entry.FullName.Replace('/', '\')

            foreach ($path in $ThemePaths) {
                if ($entryPath.StartsWith($path + '\', [StringComparison]::OrdinalIgnoreCase) -and
                    $targets -notcontains $path) {
                    $targets += $path
                }
            }
        }

        if ($snapshotScope -eq 'theme') {
            foreach ($path in $targets) {
                $target = Join-Path $Root $path

                if (Test-Path -LiteralPath $target) {
                    Remove-Item -LiteralPath $target -Recurse -Force
                }
            }
        }

        foreach ($entry in $zip.Entries) {
            if (-not $entry.Name) {
                continue
            }

            $relative = $entry.FullName.Replace('/', '\')

            if ($snapshotScope -eq 'site' -and (Test-Excluded $relative)) {
                continue
            }

            $target = Join-Path $Root $relative
            $targetDir = Split-Path -Parent $target

            if (-not (Test-Path -LiteralPath $targetDir)) {
                New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
            }

            [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $target, $true)
            $restoredCount++
        }
    }
    finally {
        $zip.Dispose()
    }

    if ($restoredCount -eq 0) {
        throw 'В архиве снимка нет файлов: снимок пуст или повреждён.'
    }

    $mysql = Find-MysqlTool 'mysql'

    Invoke-Mysql $mysql @(
        ('--host=' + $Db.Host),
        ('--port=' + $Db.Port),
        ('--user=' + $Db.User),
        ('--execute=DROP DATABASE IF EXISTS `' + $Db.Database + '`; CREATE DATABASE `' + $Db.Database + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    )

    $previousPassword = $env:MYSQL_PWD
    $env:MYSQL_PWD = $Db.Password

    try {
        $import = Start-Process -FilePath $mysql -ArgumentList @(
            ('--host=' + $Db.Host),
            ('--port=' + $Db.Port),
            ('--user=' + $Db.User),
            '--default-character-set=utf8mb4'
        ) -RedirectStandardInput $dbPath -NoNewWindow -Wait -PassThru

        if ($import.ExitCode -ne 0) {
            throw "Импорт дампа завершился с кодом $($import.ExitCode)."
        }
    }
    finally {
        $env:MYSQL_PWD = $previousPassword
    }

    Clear-LandingCache

    Write-Output ("Откат к «" + $SnapshotName + "» выполнен: файлов " + $restoredCount + ", БД " + $Db.Database + ". Запустите сервер на порту " + $ServerPort + ".")
}

function Show-Snapshots {
    if (-not (Test-Path -LiteralPath $BackupsRoot)) {
        Write-Output 'Снимков нет: папка backup ещё не создана.'
        return
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $rows = Get-ChildItem -LiteralPath $BackupsRoot -Directory | Sort-Object LastWriteTime -Descending | ForEach-Object {
        $meta = $null
        $metaPath = Join-Path $_.FullName 'meta.json'

        if (Test-Path -LiteralPath $metaPath) {
            try {
                $meta = Get-Content -LiteralPath $metaPath -Raw -Encoding UTF8 | ConvertFrom-Json
            }
            catch {
                $meta = $null
            }
        }

        $size = ($_.GetFiles() | Measure-Object Length -Sum).Sum
        $files = ''
        $scope = ''
        $note = ''

        if ($meta) {
            if ($meta.PSObject.Properties['files']) {
                $files = $meta.files
            }

            if ($meta.PSObject.Properties['scope']) {
                $scope = $meta.scope
            }

            if ($meta.PSObject.Properties['note']) {
                $note = $meta.note
            }
        }

        if (-not $files -or -not $scope) {
            $archivePath = Join-Path $_.FullName 'files.zip'

            if (-not (Test-Path -LiteralPath $archivePath)) {
                $archivePath = Join-Path $_.FullName 'theme.zip'
            }

            if (Test-Path -LiteralPath $archivePath) {
                if (-not $files) {
                    try {
                        $archive = [IO.Compression.ZipFile]::OpenRead($archivePath)
                        $files = @($archive.Entries | Where-Object { $_.Name }).Count
                        $archive.Dispose()
                    }
                    catch {
                        $files = ''
                    }
                }

                if (-not $scope) {
                    $scope = if ($archivePath -like '*\theme.zip') { 'theme (старый формат)' } else { 'theme' }
                }
            }
        }

        [pscustomobject]@{
            Name        = $_.Name
            Created     = $_.LastWriteTime.ToString('yyyy-MM-dd HH:mm')
            Scope       = $scope
            Files       = $files
            SizeMB      = [math]::Round($size / 1MB, 1)
            Description = $note
        }
    }

    if (-not $rows) {
        Write-Output 'Снимков нет.'
        return
    }

    $rows | Format-Table -AutoSize
    Write-Output ('Папка снимков: ' + $BackupsRoot)
}

$Db = Get-DbSettings

switch ($Action) {
    'backup' { New-Snapshot $Name $Note }
    'restore' { Restore-Snapshot $Name }
    'list' { Show-Snapshots }
}
