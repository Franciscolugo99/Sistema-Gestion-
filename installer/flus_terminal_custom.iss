; FLUS Terminal installer - acceso al servidor remoto
#define MyAppName "FLUS Terminal"
#define MyAppVersion "4.2.8"
#define MyAppVersionNumeric "4.2.8.0"
#define MyAppId "{{2D0D8E69-8F59-43E7-9C83-013D7D7EF1A2}}"

[Setup]
AppId={#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} v{#MyAppVersion}
DefaultDirName={localappdata}\FLUS Terminal
DisableDirPage=yes
DefaultGroupName=FLUS
OutputDir=output
OutputBaseFilename=FLUS_Terminal_Setup_{#MyAppVersion}
PrivilegesRequired=lowest

VersionInfoVersion={#MyAppVersionNumeric}
VersionInfoProductVersion={#MyAppVersionNumeric}
VersionInfoCompany=FLUS
VersionInfoDescription=Instalador FLUS Terminal
VersionInfoProductName=FLUS Terminal

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

[Tasks]
Name: "desktopicon"; Description: "Crear acceso directo en el Escritorio"; GroupDescription: "Accesos directos:"; Flags: unchecked

[Files]
Source: "..\payload\app\favicon.ico"; DestDir: "{app}"; DestName: "flus.ico"; Flags: ignoreversion

[Icons]
Name: "{group}\Terminal\Abrir FLUS"; \
  Filename: "cmd.exe"; \
  Parameters: "/c start """" ""{code:GetServerBaseUrl}"""; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"

Name: "{group}\Terminal\Seleccionar terminal"; \
  Filename: "cmd.exe"; \
  Parameters: "/c start """" ""{code:GetTerminalSelectUrl}"""; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"

Name: "{autodesktop}\FLUS Terminal"; \
  Filename: "cmd.exe"; \
  Parameters: "/c start """" ""{code:GetTerminalSelectUrl}"""; \
  WorkingDir: "{app}"; \
  IconFilename: "{app}\flus.ico"; \
  Tasks: desktopicon

[Run]
Filename: "{code:GetTerminalSelectUrl}"; \
  Description: "Abrir FLUS ahora"; \
  Flags: shellexec postinstall nowait skipifsilent

[Code]
var
  ServerUrlPage: TInputQueryWizardPage;

function SavedUrlPath(): string;
begin
  Result := ExpandConstant('{localappdata}\FLUS Terminal\server_url.txt');
end;

function NormalizeBaseUrl(const Raw: string): string;
var
  ResultLower: string;
begin
  Result := Trim(Raw);
  StringChangeEx(Result, '\', '/', True);
  while (Length(Result) > 0) and (Result[Length(Result)] = '/') do
    Delete(Result, Length(Result), 1);

  if Result = '' then
    exit;

  if Pos('://', Result) = 0 then
    Result := 'http://' + Result;

  ResultLower := LowerCase(Result);
  if (Pos('http://', ResultLower) <> 1) and (Pos('https://', ResultLower) <> 1) then
  begin
    Result := '';
    exit;
  end;

  if Pos('.php', ResultLower) > 0 then
  begin
    while (Length(Result) > 0) and (Result[Length(Result)] <> '/') do
      Delete(Result, Length(Result), 1);
    while (Length(Result) > 0) and (Result[Length(Result)] = '/') do
      Delete(Result, Length(Result), 1);
  end;
end;

function BaseUrlValue(): string;
begin
  Result := NormalizeBaseUrl(ServerUrlPage.Values[0]);
end;

function EnsureTrailingSlash(const Value: string): string;
begin
  Result := Value;
  if (Result <> '') and (Result[Length(Result)] <> '/') then
    Result := Result + '/';
end;

function GetServerBaseUrl(Param: string): string;
begin
  Result := EnsureTrailingSlash(BaseUrlValue());
end;

function GetTerminalSelectUrl(Param: string): string;
begin
  Result := GetServerBaseUrl('') + 'terminal_select.php';
end;

procedure InitializeWizard();
var
  SavedUrl: AnsiString;
begin
  ServerUrlPage := CreateInputQueryPage(
    wpWelcome,
    'Servidor FLUS',
    'Configura la URL del servidor al que se conectara esta terminal.',
    'Ingresa la URL base del servidor FLUS. Ejemplos: http://192.168.0.10:8080 o http://192.168.0.10/flus/public'
  );

  ServerUrlPage.Add('URL del servidor:', False);
  ServerUrlPage.Values[0] := 'http://localhost:8080';

  if LoadStringFromFile(SavedUrlPath(), SavedUrl) then
  begin
    SavedUrl := Trim(SavedUrl);
    if SavedUrl <> '' then
      ServerUrlPage.Values[0] := SavedUrl;
  end;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Normalized: string;
begin
  Result := True;

  if CurPageID = ServerUrlPage.ID then
  begin
    Normalized := BaseUrlValue();
    if Normalized = '' then
    begin
      MsgBox('Ingresa una URL valida. Debe comenzar con http:// o https://.', mbError, MB_OK);
      Result := False;
      exit;
    end;

    ServerUrlPage.Values[0] := EnsureTrailingSlash(Normalized);
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
    SaveStringToFile(SavedUrlPath(), GetServerBaseUrl(''), False);
end;
