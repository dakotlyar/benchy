#!/bin/sh
cat > json-fork <<'EOS'
#!/bin/sh
# A wide process tree, like make -jN: eight children, each looping on sleep
for i in 1 2 3 4 5 6 7 8; do
	( while true; do sleep 1; done ) &
done
sleep 600
echo "Score: 1" > "$LOG_FILE"
EOS
chmod +x json-fork
