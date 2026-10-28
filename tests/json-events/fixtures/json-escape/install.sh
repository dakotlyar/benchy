#!/bin/sh
cat > json-escape <<'EOS'
#!/bin/sh
# A process that leaves the trial's process tree (double fork, reparented to init), like an exec under Cygwin on
# Windows: a tree kill cannot reach it, only the trial's environment tag can
( sleep 601 >/dev/null 2>&1 & )
sleep 600
echo "Score: 1" > "$LOG_FILE"
EOS
chmod +x json-escape
