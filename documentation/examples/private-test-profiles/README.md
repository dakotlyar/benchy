# Example private test profiles

`passmark/` is a from-scratch sketch of a Windows-only PassMark PerformanceTest profile for the
private repository. It has not been run on Windows. Before uploading:

- Replace the MD5/SHA256/FileSize placeholders in `downloads.xml` with the real `petst.exe` values.
- Confirm the PerformanceTest command-line switch, the silent-install switch and the results file
  name/format used in the PowerShell scripts embedded in `install_windows.sh`.
- Keep `*.sh` files LF-only; the server rejects CRLF there.

Copy it to `~/.benchy/test-profiles/local/passmark`, run `benchy validate-test-profile local/passmark`, then
`benchy upload-private-test-profile local/passmark`.
