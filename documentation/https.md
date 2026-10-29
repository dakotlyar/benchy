# Built-in HTTPS for the Phoromatic server (Benchy 0.9, Linux servers)

The Phoromatic server runs on PHP's built-in web server, which cannot speak TLS. Benchy therefore starts a **Benchy-managed Caddy**
in front of it: Caddy terminates TLS on the HTTPS port (default **39443**), PHP moves to `127.0.0.1` and only answers Caddy. HTTPS is
**off by default**: an upgraded server behaves like 0.8 until an administrator turns it on. The server does not run on Windows;
Windows matters for the client side below.

## Server (run on the server host as the user that owns the database, e.g. `sudo -u phoromatic benchy ...`)

    benchy server-caddy-install                      # the Caddy pinned by this Benchy (size + sha256 checked); --use PATH for a distro Caddy (2.8+)
    benchy server-https-set internal --names seinar,192.168.100.118 [--http-mode serve|redirect|off]
    systemctl restart phoromatic-server              # turning HTTPS on or off needs a restart
    benchy server-https-status                       # certificate presented, and the built-in CA's SHA-256 fingerprint for clients
    benchy server-https-off                          # back to plain "php -S" on the HTTP port (restart again)

Certificate sources: `internal` (Caddy's own CA), `file` (`server-https-set file --cert chain.pem --key key.pem`, or `--pfx FILE
--pfx-password-file FILE`; key mismatch, expired and not-yet-valid certificates are refused) and `acme` (needs ports 443 and 80).
Changes that keep HTTPS on (a new certificate, the plain-HTTP mode, names) apply to the running service by restarting only Caddy; if
the new configuration does not come up the previous one is restored and the Settings page says so.

The **Settings** page of the web interface (administrators of level 1) has the same certificate upload, **only over HTTPS or from
localhost**, so a private key never crosses the network in plain text. On plain HTTP it offers *Turn on HTTPS with the built-in CA*
instead (it sends no secret); after the restart, accept the built-in CA in the browser once (compare its fingerprint with
`server-https-status`), then upload the real certificate. Every change is in the admin audit log (`https_cert_upload`,
`https_mode_change`) with the certificate fingerprint, never the key or a PFX password.

Plain HTTP (`--http-mode`): `serve` (default while clients migrate) keeps the client endpoints on the HTTP port for older clients
and redirects the web interface to HTTPS; `redirect` redirects GET/HEAD and answers other requests with an error that names the fix;
`off` has no plain listener. The systems list shows a lock for systems whose last check-in was over HTTPS.

## Clients (Linux and Windows)

A client verifies the certificate of every Benchy server it talks to over `https://`: first against an optional CA file
(`BENCHY_SERVER_CAFILE`, else `server-ca.pem` in the user path), then against the system store. A certificate error stops the
request; there is no setting to ignore it and no fallback to HTTP. Server strings are `https://host:port/ACCOUNT` (or the old
`host:port/ACCOUNT`, which means plain HTTP).

    benchy server-trust https://seinar:39443/ACCOUNT --fingerprint <SHA-256 from server-https-status>
    benchy server-trust https://seinar:39443/ACCOUNT --ca-file corporate-ca.pem
    benchy server-trust https://seinar:39443/ACCOUNT                 # only checks the system store

`server-trust` keeps the CA only if the fingerprint matches, and switches the stored Phoromatic and private-repository addresses
for that host and account to the `https://` string.

Caddy is started by the same launcher, so it runs as the service user (`phoromatic`), not root, and binds only ports above 1024 (39443
and the HTTP port), so **no capability is needed**. Only ACME, which must use ports 443 and 80, needs `CAP_NET_BIND_SERVICE`
(`setcap` on the Caddy binary, or `AmbientCapabilities=` in the unit). PHP also refuses any request that carries an `X-Benchy-*` header
other than the four Caddy sets (including underscore spellings), and Caddy deletes the underscore spellings.

Note: `server-ca.pem` is accepted for **every** Benchy server this client knows, not only the one it was fetched from; per-host
scoping is later hardening.

## PHP workers

`php -S` runs `PHP_CLI_SERVER_WORKERS` workers (default 8; `PHOROMATIC_PHP_WORKERS` overrides). A worker serves one request at a
time; check-ins are short, long transfers (test downloads from the server's cache) are what fill workers.
Rule of thumb: `workers = 4 + the peak number of clients downloading tests from the server's cache at the same time`. 8 for a homelab,
16 for 50-100 systems on staggered schedules, 32 for 100+ systems that install at once (and stagger the ticket). Beyond that, or on
a Windows server, or when check-in latency stays above 1 s at the 95th percentile (Caddy's access log in `tls/caddy.log` has the
durations), the server needs php-fpm behind Caddy (post-1.0).
