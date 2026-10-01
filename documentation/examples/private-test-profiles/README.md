# Example private test profiles

`passmark/` is a Windows-only PassMark PerformanceTest profile for the private repository. It was ported from the
version that ran on a real Windows host. The downloads.xml checksums are the real `petst.exe` values; there are no
placeholders left to fill in.

How it is put together (the profile package may only contain the fixed set of files `validate-test-profile` permits, so
the rest is carried inside `install_windows.sh` and written out when it runs under Benchy's Cygwin bash):

- `install_windows.sh` writes the test executable wrapper first (so Benchy always finds it), then writes `run.sh` and
  `benchmark/passmark-benchmark.ps1`, finds `powershell.exe` by full path (falling back to the PATH lookup), installs
  PerformanceTest silently from the downloaded `petst.exe` if needed, and records what it found in `platform.txt`
  (`pt_exe=` and `ps_exe=`).
- `run.sh` reads `platform.txt`, then runs the PowerShell workload script with `-ExecutionPolicy Bypass -File` and a
  Windows path made with `cygpath -w`, using the `ps_exe=` PowerShell it found at install time.
- Unattended runs need a licensed, activated PerformanceTest; the profile does not activate it.
- Keep `*.sh` files LF-only; the server rejects CRLF there.

Copy it to `~/.benchy/test-profiles/local/passmark`, run `benchy validate-test-profile local/passmark`, then
`benchy upload-private-test-profile local/passmark` (or zip the folder and use the Upload tab of the Private Repository
page in the Phoromatic web UI).
