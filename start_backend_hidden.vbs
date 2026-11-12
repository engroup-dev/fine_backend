Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
batPath = Chr(34) & fso.BuildPath(scriptDir, "start_backend.bat") & Chr(34)

' Run completely hidden (window style 0), asynchronous (False)
shell.Run "cmd.exe /c " & batPath, 0, False
