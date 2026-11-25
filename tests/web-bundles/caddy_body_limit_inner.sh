#!/bin/bash
# Runs inside the container of caddy_body_limit.sh
set -u
W=/tmp/cbl; mkdir -p $W/tls $W/up; cd $W
tar xzf /opt/caddy/caddy.tar.gz -C $W caddy
./caddy version | head -1
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 -keyout k.pem -out c.pem 2>/dev/null
# the stub upstream: answers 200 and the byte count to anything that reaches it
cat > up/r.php <<'PHP'
<?php echo 'upstream ok ' . strlen(file_get_contents('php://input'));
PHP
php -d post_max_size=200M -d upload_max_filesize=200M -S 127.0.0.1:39082 -t up up/r.php >/dev/null 2>&1 &
# the config exactly as the server generates it (file mode, http off)
php -d display_errors=stderr -r '
define("PTS_PATH", "/bench/");
class phoromatic_server { static function phoromatic_path($c = true) { return "/tmp/cbl/"; } }
require "/bench/pts-core/objects/phoromatic/phoromatic_tls.php";
$s = phoromatic_tls::defaults();
$s["mode"] = "file"; $s["names"] = array("127.0.0.1"); $s["http_mode"] = "off"; $s["https_port"] = 39443;
$s["file"] = array("cert" => "/tmp/cbl/c.pem", "key" => "/tmp/cbl/k.pem");
$c = phoromatic_tls::caddy_config($s, "secret", 39082, 39080, null, null);
$c = json_decode(json_encode($c));
$c->storage = (object)array("module" => "file_system", "root" => "/tmp/cbl/storage");
echo json_encode($c, JSON_UNESCAPED_SLASHES);' > cfg.json 2>cfg.err || { echo "FAIL: could not generate the config"; head -5 cfg.err; exit 1; }
./caddy run --config cfg.json > caddy.log 2>&1 &
for i in $(seq 40); do curl -sk -o /dev/null https://127.0.0.1:39443/ && break; sleep 0.5; done
head -c 62914560 /dev/zero > body.bin
fail=0
post() { curl -sk --http1.1 -o /dev/null -w "%{http_code}" -X POST --data-binary @body.bin "https://127.0.0.1:39443$1" || true; }
expect() { local got; got=$(post "$2"); if [ "$got" = "$3" ]; then echo "  PASS  60 MB POST to $2 -> $got ($1)"; else echo "  FAIL  60 MB POST to $2 -> $got, expected $3 ($1)"; fail=1; fi; }
expect "bundle page, passes Caddy" "/?server_catalog" 200
expect "bundle page, passes Caddy" "/?admin_config" 200
expect "any other page keeps the small limit" "/?login" 413
expect "the root keeps the small limit" "/" 413
[ $fail = 0 ] && echo "caddy body limits: PASS" || { echo "caddy body limits: FAIL"; tail -20 caddy.log; }
exit $fail
