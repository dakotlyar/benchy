#!/bin/sh
# A trial of 4 s while the engine's forked SystemMonitor child samples cpu.usage
cat > json-monitor <<'EOS'
#!/bin/sh
sleep 4
EOS
chmod +x json-monitor
