; FLUS Servidor installer - ES + Servicios OK (Admin)
#define MyAppName "FLUS Servidor (Multi-caja)"
#define MyAppVersion "4.2.8"
#define MyAppVersionNumeric "4.2.8.0"
#define MyAppId "{{8F8B8A6E-4C8B-4C7D-9B7C-5A1D4F1C15A1}}"

[Setup]
AppId={#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} v{#MyAppVersion}
DefaultDirName={code:GetDefaultDir}
DisableDirPage=no
DefaultGroupName=FLUS
OutputDir=output
OutputBaseFilename=FLUS_Server_Setup_{#MyAppVersion}
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible

VersionInfoVersion={#MyAppVersionNumeric}
VersionInfoProductVersion={#MyAppVersionNumeric}
VersionInfoCompany=FLUS
VersionInfoDescription=Instalador FLUS Servidor
VersionInfoProductName=FLUS Servidor

SetupIconFile=..\payload\app\favicon.ico
UninstallDisplayIcon={app}\flus.ico

WizardStyle=modern
WizardImageFile=assets\wizard-left.bmp
WizardSmallImageFile=assets\wizard-small.bmp

Compression=lzma2
SolidCompression=yes
SetupLogging=yes

[Languages]
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"

[Files]
; App (fresh) - copia completa
Source: "..\payload\app\*"; DestDir: "{app}\app"; \
  Flags: recursesubdirs createallsubdirs ignoreversion; \
  Check: IsFreshInstall

; App (upgrade) - no pisar config ni storage
Source: "..\payload\app\*"; DestDir: "{app}\app"; \
  Flags: recursesubdirs createallsubdirs ignoreversion; \
  Excludes: "storage\*,storage\*\*,src\config.php,src\config.local.php,src\config_arca.php,src\config_mp.php,src\config*.bak*"; \
  Check: IsUpgradeInstall

; DB - baseline + migraciones
Source: "..\payload\db\*"; DestDir: "{app}\db"; \
  Flags: recursesubdirs createallsubdirs ignoreversion

; Icono estable
Source: "..\payload\app\favicon.ico"; DestDir: "{app}"; \
  DestName: "flus.ico"; Flags: ignoreversion

; Stack solo en instalacion nueva
Source: "..\payload\stack\*"; DestDir: "{app}\stack"; \
  Flags: recursesubdirs createallsubdirs ignoreversion; \
  Check: IsFreshInstall

; Scripts
Source: "postinstall_server.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "upgrade_db.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "flus_services.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "start_services.cmd"; DestDir: "{app}"; Flags: ignoreversion
Source: "stop_services.cmd"; DestDir: "{app}"; Flags: ignoreversion
Source: "status_services.cmd"; DestDir: "{app}"; Flags: ignoreversion
Source: "preupgrade.ps1"; Flags: dontcopy
Source: "postupgrade.ps1"; Flags: dontcopy
Source: "postupgrade.ps1"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{app}\app\storage"; Flags: uninsneveruninstall
Name: "{app}\app\storage\logs"; Flags: uninsneveruninstall
Name: "{app}\app\storage\uploads"; Flags: uninsneveruninstall
Name: "{app}\app\storage\backups"; Flags: uninsneveruninstall
Name: "{app}\db\backups"; Flags: uninsneveruninstall
Name: "{app}\logs"; Flags: uninsneveruninstall
Name: "{app}\upgrade_backups"; Flags: uninsneveruninstall

[InstallDelete]
Type: files; Name: "{app}\db\migrations\*.sql"; Check: IsUpgradeInstall
Type: files; Name: "{app}\db\migrations\manifest.txt"; Check: IsUpgradeInstall

[Icons]
Name: "{group}\Servidor\Abrir FLUS (Web)"; \
  Filename: "{app}\FLUS.url"; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"

Name: "{group}\Servidor\Servicios\Iniciar servicios (Admin)"; \
  Filename: "{app}\start_services.cmd"; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"

Name: "{group}\Servidor\Servicios\Detener servicios (Admin)"; \
  Filename: "{app}\stop_services.cmd"; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"

Name: "{group}\Servidor\Servicios\Ver estado (Admin)"; \
  Filename: "{app}\status_services.cmd"; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"

Name: "{group}\Servidor\Configuracion\Configurar Cloud"; \
  Filename: "{app}\app\scripts\Configurar Cloud FLUS.cmd"; \
  WorkingDir: "{app}\app"; \
  IconFilename: "{app}\flus.ico"

[UninstallRun]
Filename: "cmd.exe"; \
  Parameters: "/c net stop FLUS_Apache & net stop FLUS_MariaDB & sc.exe delete FLUS_Apache & sc.exe delete FLUS_MariaDB"; \
  RunOnceId: "StopAndRemoveFlusServices"; \
  Flags: runhidden

[Run]
Filename: "{app}\app\scripts\Configurar Cloud FLUS.cmd"; \
  Description: "Configurar o reparar Cloud"; \
  WorkingDir: "{app}\app"; \
  Flags: shellexec postinstall skipifsilent unchecked

Filename: "{code:GetLaunchUrl}"; \
  Description: "Abrir FLUS ahora"; \
  Flags: shellexec postinstall nowait skipifsilent runasoriginaluser; \
  Check: CanLaunchFlus

[Code]
var
  PrevInstall: Boolean;
  PrevInstallDir: string;
  ServicesDetected: Boolean;
  UpgradePrepared: Boolean;
  UpgradeServicesRestored: Boolean;
  DbPage: TInputQueryWizardPage;

function RunPowerShellScript(const ScriptPath: string; const RootPath: string; const ExtraParams: string; const WindowStyle: Integer; var ResultCode: Integer): Boolean;
var
  Params: string;
begin
  Params := '-ExecutionPolicy Bypass -NoProfile -File "' + ScriptPath + '" -Root "' + RootPath + '" ' + ExtraParams;
  Result := Exec('powershell.exe', Params, '', WindowStyle, ewWaitUntilTerminated, ResultCode);
end;

function PrepareToInstall(var NeedsRestart: Boolean): string;
var
  ResultCode: Integer;
  ScriptPath: string;
begin
  Result := '';
  if not PrevInstall then
    exit;

  try
    ExtractTemporaryFile('preupgrade.ps1');
    ExtractTemporaryFile('postupgrade.ps1');
    ScriptPath := ExpandConstant('{tmp}\preupgrade.ps1');
    if not RunPowerShellScript(ScriptPath, ExpandConstant('{app}'), '', SW_HIDE, ResultCode) then
      Result := 'No se pudo iniciar el backup previo de FLUS.'
    else if ResultCode <> 0 then
      Result := 'El backup previo no se completo. La actualizacion fue cancelada sin reemplazar archivos.'
    else
      UpgradePrepared := True;
  except
    Result := 'No se pudo preparar el backup previo. La actualizacion fue cancelada.';
  end;
end;

procedure DeinitializeSetup();
var
  ResultCode: Integer;
begin
  if UpgradePrepared and (not UpgradeServicesRestored) then
  begin
    RunPowerShellScript(
      ExpandConstant('{tmp}\postupgrade.ps1'),
      ExpandConstant('{app}'),
      '',
      SW_HIDE,
      ResultCode
    );
  end;
end;

function GetUninstallKey(): string;
begin
  Result := ExpandConstant('Software\Microsoft\Windows\CurrentVersion\Uninstall\{#MyAppId}_is1');
end;

function TryReadPrevDir(const RootKey: Integer; var Dir: string): Boolean;
var
  V: string;
begin
  Result := False;

  if RegQueryStringValue(RootKey, GetUninstallKey(), 'Inno Setup: App Path', V) then
  begin
    Dir := V;
    Result := True;
    exit;
  end;

  if RegQueryStringValue(RootKey, GetUninstallKey(), 'InstallLocation', V) then
  begin
    Dir := V;
    Result := True;
    exit;
  end;
end;

function DetectPrevInstallDir(): string;
var
  D: string;
begin
  D := '';

  if TryReadPrevDir(HKLM, D) then
  begin
    Result := D;
    exit;
  end;

  if TryReadPrevDir(HKLM32, D) then
  begin
    Result := D;
    exit;
  end;

  Result := '';
end;

function HasFlusServices(): Boolean;
begin
  Result :=
    RegKeyExists(HKLM, 'SYSTEM\CurrentControlSet\Services\FLUS_Apache') or
    RegKeyExists(HKLM, 'SYSTEM\CurrentControlSet\Services\FLUS_MariaDB');
end;

function IsValidExistingRoot(const Dir: string): Boolean;
var
  P: string;
begin
  P := AddBackslash(Dir);
  Result :=
    DirExists(P + 'app') and
    (DirExists(P + 'stack') or FileExists(P + 'unins000.exe') or FileExists(P + 'unins001.exe'));
end;

function InitializeSetup(): Boolean;
begin
  PrevInstallDir := DetectPrevInstallDir();
  if (PrevInstallDir <> '') and (not IsValidExistingRoot(PrevInstallDir)) then
    PrevInstallDir := '';

  ServicesDetected := HasFlusServices();
  PrevInstall := (PrevInstallDir <> '');
  Result := True;
end;

function GetDefaultDir(Param: string): string;
begin
  if PrevInstallDir <> '' then
    Result := PrevInstallDir
  else
    Result := 'C:\FLUS';
end;

function IsFreshInstall(): Boolean;
begin
  Result := not PrevInstall;
end;

function IsUpgradeInstall(): Boolean;
begin
  Result := PrevInstall;
end;

function IsSafeDbIdentifier(const S: string): Boolean;
var
  I: Integer;
  C: string;
begin
  Result := (Length(S) > 0);
  for I := 1 to Length(S) do
  begin
    C := Copy(S, I, 1);
    if Pos(C, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_') = 0 then
    begin
      Result := False;
      exit;
    end;
  end;
end;

function PsSingleQuote(const Value: string): string;
begin
  Result := Value;
  StringChangeEx(Result, #39, #39 + #39, True);
  Result := #39 + Result + #39;
end;

function PsDoubleQuote(const Value: string): string;
begin
  Result := Value;
  StringChangeEx(Result, '\', '\\', True);
  StringChangeEx(Result, '"', '\"', True);
  Result := '"' + Result + '"';
end;

function StripLineBreaks(const Value: string): string;
begin
  Result := Value;
  StringChangeEx(Result, #13, '', True);
  StringChangeEx(Result, #10, '', True);
  Result := Trim(Result);
end;

function IsHttpUrl(const Value: string): Boolean;
var
  Lower: string;
begin
  Lower := LowerCase(Value);
  Result := (Pos('http://', Lower) = 1) or (Pos('https://', Lower) = 1);
end;

function FirstLineOf(const Value: string): string;
var
  P: Integer;
begin
  Result := Value;
  P := Pos(#13, Result);
  if P = 0 then
    P := Pos(#10, Result);
  if P > 0 then
    Result := Copy(Result, 1, P - 1);
  Result := StripLineBreaks(Result);
end;

function ReadFirstLineFile(const Path: string): string;
var
  Raw: AnsiString;
begin
  Result := '';
  if LoadStringFromFile(Path, Raw) then
    Result := FirstLineOf(Raw);
end;

function ReadUrlShortcut(const Path: string): string;
var
  Raw: AnsiString;
  S: string;
  P: Integer;
begin
  Result := '';
  if LoadStringFromFile(Path, Raw) then
  begin
    S := Raw;
    P := Pos('URL=', S);
    if P > 0 then
      Result := FirstLineOf(Copy(S, P + 4, Length(S)));
  end;
end;

function ReadLaunchUrlFromLog(const Path: string): string;
var
  Raw: AnsiString;
  S: string;
  P: Integer;
begin
  Result := '';
  if LoadStringFromFile(Path, Raw) then
  begin
    S := Raw;
    P := Pos('OK -> App: ', S);
    if P > 0 then
      Result := FirstLineOf(Copy(S, P + 11, Length(S)));
  end;
end;

function GetLaunchUrl(Param: string): string;
var
  Candidate: string;
begin
  Candidate := ReadFirstLineFile(ExpandConstant('{app}\flus_launch_url.txt'));
  if IsHttpUrl(Candidate) then
  begin
    Result := Candidate;
    exit;
  end;

  Candidate := ReadUrlShortcut(ExpandConstant('{app}\FLUS.url'));
  if IsHttpUrl(Candidate) then
  begin
    Result := Candidate;
    exit;
  end;

  Candidate := ReadLaunchUrlFromLog(ExpandConstant('{app}\install.log'));
  if IsHttpUrl(Candidate) then
  begin
    Result := Candidate;
    exit;
  end;

  Result := 'http://localhost:8080/';
end;

function CanLaunchFlus(): Boolean;
begin
  Result := IsHttpUrl(GetLaunchUrl(''));
end;

procedure EnsureLaunchShortcut();
var
  Url: string;
  Icon: string;
  Content: string;
begin
  Url := GetLaunchUrl('');
  if not IsHttpUrl(Url) then
    exit;

  Icon := ExpandConstant('{app}\flus.ico');
  Content := '[InternetShortcut]' + #13#10 +
    'URL=' + Url + #13#10 +
    'IconFile=' + Icon + #13#10 +
    'IconIndex=0' + #13#10;

  SaveStringToFile(ExpandConstant('{app}\FLUS.url'), Content, False);
  SaveStringToFile(ExpandConstant('{app}\flus_launch_url.txt'), Url + #13#10, False);
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := ((PageID = wpSelectDir) and PrevInstall and (PrevInstallDir <> '')) or
    ((DbPage <> nil) and (PageID = DbPage.ID) and PrevInstall);
end;

procedure InitializeWizard();
begin
  DbPage := CreateInputQueryPage(
    wpSelectDir,
    'Base de datos',
    'Configuracion para instalacion nueva',
    'Elegi los datos que FLUS usara para crear la base local y generar config.php.'
  );
  DbPage.Add('Nombre de base:', False);
  DbPage.Add('Usuario DB:', False);
  DbPage.Add('Clave DB:', True);
  DbPage.Values[0] := 'flus_db';
  DbPage.Values[1] := 'flus_user';
  DbPage.Values[2] := 'flus1234';

  if PrevInstall then
  begin
    MsgBox(
      'Se detecto FLUS instalado en:' + #13#10 + PrevInstallDir + #13#10#13#10 +
      'El instalador realizara una ACTUALIZACION.' + #13#10 +
      'No se tocan datos ni configuraciones del cliente.',
      mbInformation, MB_OK
    );
  end
  else if ServicesDetected then
  begin
    MsgBox(
      'Se detectaron servicios FLUS instalados, pero no una instalacion valida registrada.' + #13#10 +
      'Si elegis una carpeta vacia, se hara una instalacion NUEVA y la base se reinicializara desde cero.' + #13#10 +
      'Si elegis una carpeta donde ya existe FLUS, se hara una ACTUALIZACION.',
      mbInformation, MB_OK
    );
  end
  else
  begin
    MsgBox(
      'No se detecto FLUS instalado.' + #13#10 +
      'Se realizara una instalacion NUEVA.',
      mbInformation, MB_OK
    );
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Chosen: string;
begin
  Result := True;

  if CurPageID = wpSelectDir then
  begin
    Chosen := ExpandConstant('{app}');

    if PrevInstall and (PrevInstallDir = '') then
    begin
      if not IsValidExistingRoot(Chosen) then
      begin
        MsgBox(
          'Se detectaron servicios FLUS, pero no se encontro la carpeta instalada automaticamente.' + #13#10 +
          'Elegi la carpeta correcta donde ya esta FLUS (debe contener "\app" y "\stack").',
          mbError, MB_OK
        );
        Result := False;
      end
      else
      begin
        PrevInstallDir := Chosen;
      end;
    end
    else if (not PrevInstall) and IsValidExistingRoot(Chosen) then
    begin
      PrevInstallDir := Chosen;
      PrevInstall := True;
      MsgBox(
        'La carpeta seleccionada ya contiene una instalacion valida de FLUS.' + #13#10 +
        'El instalador continuara en modo ACTUALIZACION para preservar datos y configuracion.',
        mbInformation, MB_OK
      );
    end;
  end;

  if (DbPage <> nil) and (CurPageID = DbPage.ID) then
  begin
    if not IsSafeDbIdentifier(DbPage.Values[0]) then
    begin
      MsgBox('El nombre de la base solo puede usar letras, numeros y guion bajo.', mbError, MB_OK);
      Result := False;
      exit;
    end;

    if not IsSafeDbIdentifier(DbPage.Values[1]) then
    begin
      MsgBox('El usuario de base solo puede usar letras, numeros y guion bajo.', mbError, MB_OK);
      Result := False;
      exit;
    end;

    if DbPage.Values[2] = '' then
    begin
      MsgBox('La clave de la base no puede estar vacia.', mbError, MB_OK);
      Result := False;
      exit;
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  RestoreCode: Integer;
  CloudCode: Integer;
  Params: string;
  MigrationStarted: Boolean;
  RestoreStarted: Boolean;
  CloudStarted: Boolean;
begin
  if CurStep = ssPostInstall then
  begin
    if IsFreshInstall() then
    begin
      Params := '-ExecutionPolicy Bypass -NoProfile -File "' + ExpandConstant('{app}\postinstall_server.ps1') + '" -Root "' + ExpandConstant('{app}') + '" -Port 8080 -DbPort 3307 -ResetDb' +
        ' -DbName ' + PsDoubleQuote(DbPage.Values[0]) +
        ' -DbUser ' + PsDoubleQuote(DbPage.Values[1]) +
        ' -DbPass ' + PsDoubleQuote(DbPage.Values[2]);
      if not Exec('powershell.exe', Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
      begin
        MsgBox('No se pudo ejecutar el postinstall (powershell.exe).', mbError, MB_OK);
        Abort;
      end;
      if ResultCode <> 0 then
      begin
        MsgBox('Postinstall fallo. Revisa: ' + ExpandConstant('{app}\install.log'), mbError, MB_OK);
        Abort;
      end;
    end
    else
    begin
      MigrationStarted := RunPowerShellScript(
        ExpandConstant('{app}\upgrade_db.ps1'),
        ExpandConstant('{app}'),
        '-DbPort 3307 -NoBackup',
        SW_HIDE,
        ResultCode
      );
      RestoreStarted := RunPowerShellScript(
        ExpandConstant('{app}\postupgrade.ps1'),
        ExpandConstant('{app}'),
        '',
        SW_HIDE,
        RestoreCode
      );

      if (not RestoreStarted) or (RestoreCode <> 0) then
      begin
        MsgBox('La actualizacion termino, pero no se pudo restaurar el estado anterior de Apache. Usa FLUS > Servidor > Servicios > Iniciar servicios.', mbError, MB_OK);
        Abort;
      end;
      UpgradeServicesRestored := True;
      if (not MigrationStarted) or (ResultCode <> 0) then
      begin
        MsgBox(
          'La migracion fallo. FLUS dejo un backup previo verificado en:' + #13#10 +
          ExpandConstant('{app}\upgrade_backups') + #13#10#13#10 +
          'Revisa el detalle tecnico en:' + #13#10 + ExpandConstant('{app}\logs'),
          mbError,
          MB_OK
        );
        Abort;
      end;

      CloudStarted := RunPowerShellScript(
        ExpandConstant('{app}\app\scripts\cloud_sync_setup.ps1'),
        ExpandConstant('{app}\app'),
        '-NonInteractive -SkipMigrations',
        SW_HIDE,
        CloudCode
      );
      if (not CloudStarted) or (CloudCode <> 0) then
      begin
        MsgBox(
          'La actualizacion de FLUS termino, pero Cloud requiere reparacion.' + #13#10 +
          'Se abrira el asistente seguro para conservar o completar URL, token y tarea automatica.',
          mbInformation,
          MB_OK
        );
        Params := '/c ""' + ExpandConstant('{app}\app\scripts\Configurar Cloud FLUS.cmd') + '""';
        Exec('cmd.exe', Params, ExpandConstant('{app}\app'), SW_SHOWNORMAL, ewWaitUntilTerminated, CloudCode);
        CloudStarted := RunPowerShellScript(
          ExpandConstant('{app}\app\scripts\cloud_sync_setup.ps1'),
          ExpandConstant('{app}\app'),
          '-StatusOnly -NonInteractive',
          SW_HIDE,
          CloudCode
        );
        if (not CloudStarted) or (CloudCode <> 0) then
          MsgBox('Cloud sigue pendiente. FLUS local queda preservado, pero la sincronizacion no estara operativa hasta completar Configurar Cloud.', mbError, MB_OK);
      end;
    end;

    EnsureLaunchShortcut();
  end;
end;
