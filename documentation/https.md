# Built-in HTTPS for the Phoromatic server (Benchy 0.9, Linux servers)

The Phoromatic server runs on PHP's built-in web server, which cannot speak TLS. Benchy therefore starts a **Benchy-managed Caddy**
in front of it: Caddy terminates TLS on the HTTPS port (default **39443**), PHP moves to `127.0.0.1` and only answers Caddy. HTTPS is
**off by default**: an upgraded server behaves like 0.8 until an administrator turns it on. The server does not run on Windows;
Windows matters for the client side below.

## Server (run on the server host as the user that owns the database, e.g. `sudo -u phoromatic benchy ...`)

    benchy server-caddy-install                      # the Caddy pinned by this Benchy (size + sha256 checked); --use PATH for a distro Caddy (2.8+)
    benchy server-https-set internal --names server.example,192.0.2.10 [--http-mode serve|redirect|off]
    systemctl restart phoromatic-server              # turning HTTPS on or off needs a restart
    benchy server-https-status                       # certificate presented, and the built-in CA's SHA-256 fingerprint for clients
    benchy server-https-off                          # back to plain "php -S" on the HTTP port (restart again)

`--names` lists every host name and IP address that machines use to reach the server, including an address on the host of a NAT or a
port forward (a VM behind its host is reached by the host's address, but the connection arrives on the VM's own). In the `internal`
mode (0.10) the server makes **one certificate that carries all of them**, so each name works, also for a client that connects by IP
address and sends no server name. Before 0.10 Caddy made one certificate per name and handed an IP client the one for the address the
connection arrived on, so a NAT address failed with "certificate is valid for A, not B". The CA and its fingerprint are the ones Caddy made; a
machine paired with `server-trust --fingerprint` needs no action. The certificate is signed by that CA, lasts 30 days and is renewed with
10 days left, by the launcher's check every few hours (this needs the service, e.g. systemd; a server started by hand only checks when it starts, so restart it before the 30 days are up); Caddy restarts alone for a few seconds when it is renewed. Rolling back to 0.9.15 is safe
(Caddy goes back to a certificate per name). `server-https-status` shows the certificate a client without a server name gets, and what
each configured name gets.

Certificate sources: `internal` (Caddy's own CA), `file` (`server-https-set file --cert chain.pem --key key.pem`, or `--pfx FILE
--pfx-password-file FILE`; key mismatch, expired and not-yet-valid certificates are refused) and `acme` (needs ports 443 and 80).
Changes that keep HTTPS on (a new certificate, the plain-HTTP mode, names) apply to the running service by restarting only Caddy; if
the new configuration does not come up the previous one is restored and the Server settings page says so.

The **Server settings** page of the web interface (for server administrators; open it from the link at the bottom of the **Settings** page) and root's **Admin Config** page have the same certificate upload, **only over HTTPS or from
localhost**, so a private key never crosses the network in plain text. On plain HTTP it offers *Turn on HTTPS with the built-in CA*
instead (it sends no secret); after the restart, accept the built-in CA in the browser once (compare its fingerprint with
`server-https-status`), then upload the real certificate. Every change is in the admin audit log (`https_cert_upload`,
`https_mode_change`) with the certificate fingerprint, never the key or a PFX password.

Plain HTTP (`--http-mode`): `serve` (default while clients migrate) keeps the client endpoints on the HTTP port for older clients
and redirects the web interface to HTTPS; `redirect` redirects GET/HEAD and answers other requests with an error that names the fix;
`off` has no plain listener. The systems list shows a lock for systems whose last check-in was over HTTPS.

## Finding the server on the network (Benchy 0.10)

While the built-in HTTPS is on, the server announces itself on the local network as `_benchy._tcp`: its network name and its HTTPS port,
nothing else (no account, no count, no plain-HTTP port). The announcement is a supervised `avahi-publish` run by the launcher, so it
needs `avahi-daemon` and `avahi-utils` (the `benchy-server` package recommends both) and it comes back by itself when avahi restarts.

    benchy server-announce status            # announced or not, and why; the network name; the DNS record below
    benchy server-announce off | on          # also on the Server settings page; audited as discovery_announce_change
    benchy server-announce name "Lab rack 4" # up to 63 bytes, no control characters; "name default" uses the host name

For machines on other VLANs, where mDNS does not reach, add one record to the DNS resolver they use. `server-announce status` and the
Server settings page print it filled in with the server's own name and HTTPS port:

    local-data: "_benchy._tcp.example.com. 3600 IN SRV 0 0 39443 server.example.com."

`GET /api/v2/server` is the unauthenticated "who are you" question (HTTPS only): the network name, the version, the protocol list, the
certificate mode and names, the internal CA's fingerprint, and whether join codes are accepted. It says nothing about accounts. It is a
hint that anyone on the network could forge, so a client checks the certificate separately and trusts only a pairing string or a code.
The firewall and DNS steps are in the [administrator's guide](admin-guide/new-server.md#let-machines-find-the-server).

## Clients (Linux and Windows)

A client verifies the certificate of every Benchy server it talks to over `https://`: first against an optional CA file
(`BENCHY_SERVER_CAFILE`, else `server-ca.pem` in the user path), then, **on Linux**, against the system store. **On Windows
trust is the CA file only**: PHP 8.5.12's streams do not consult the Windows certificate store (checked on Windows), so a Windows
client needs `server-trust --fingerprint` or `--ca-file` for every server, even one with a publicly trusted certificate (the native
engine will handle Windows trust later). A certificate error stops the
request (and the upload retry loops, at once); there is no setting to ignore it and no fallback to HTTP. Server strings are `https://host:port/ACCOUNT` (or the old
`host:port/ACCOUNT`, which means plain HTTP).

    benchy server-trust https://server.example:39443/ACCOUNT --fingerprint <SHA-256 from server-https-status>
    benchy server-trust https://server.example:39443/ACCOUNT --ca-file corporate-ca.pem
    benchy server-trust https://server.example:39443/ACCOUNT                 # Linux: only checks the system store; on Windows it refuses

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
