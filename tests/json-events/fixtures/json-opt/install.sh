#!/bin/sh
cat > json-opt <<'EOS'
#!/bin/sh
mode=1
level=0
for a in "$@"; do
	case "$a" in
		--mode=*) mode=${a#--mode=} ;;
		--level=*) level=${a#--level=} ;;
	esac
done
echo "Score: $((level * 10 + mode))" > "$LOG_FILE"
EOS
chmod +x json-opt
