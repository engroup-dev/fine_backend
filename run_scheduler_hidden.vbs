Option Explicit

Dim fso, shell, scriptDir, batPath, q
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")
q = Chr(34)

scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
batPath = fso.BuildPath(scriptDir, "run_scheduler.bat")

' /s with an extra pair of outer quotes keeps the path intact even if it contains
' spaces, & or parentheses. /d skips any cmd AutoRun commands.
' Run completely hidden (window style 0) and wait (True), so the Windows task
' stays running for as long as a slow job (a backup) does and the next minute's
' run is skipped instead of piling up.
shell.Run "cmd.exe /d /s /c " & q & q & batPath & q & q, 0, True
