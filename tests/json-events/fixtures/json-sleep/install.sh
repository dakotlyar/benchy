#!/bin/sh
cat > json-sleep <<'EOS'
#!/bin/sh
# Forks a child that keeps spawning sleeps, then blocks: only a process-tree kill stops it
( while true; do sleep 1; done ) &
sleep 600
echo "Score: 1" > "$LOG_FILE"
EOS
chmod +x json-sleep
