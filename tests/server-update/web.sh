# A tiny web client for the container tests (it runs INSIDE the test container). Usage:
#   web.sh login USER PASSWORD | get PATH | post PATH 'k=v&k=v' | upload PATH FILE  (PATH like "?server_settings")
B=https://127.0.0.1:39443
J=${CJAR:-/tmp/cj}
case $1 in
 login) rm -f $J; curl -sk -c $J -b $J -o /dev/null $B/ ; curl -sk -c $J -b $J -o /tmp/login.out -w '%{http_code}\n' --data-urlencode "username=$2" --data-urlencode "password=$3" "$B/?login"; grep -o "Log-In Successful" /tmp/login.out | head -1 ;;
 get) curl -sk -b $J -c $J "$B/$2" ;;
 token) curl -sk -b $J -c $J "$B/?server_settings" | grep -o 'name="token_submit" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//' ;;
 post) TOK=$(sh $0 token); curl -sk -b $J -c $J -o /tmp/post.out -w '%{http_code}\n' -H "Origin: $B" --data "$3&token_submit=$TOK" "$B/$2" ;;
 upload) TOK=$(sh $0 token); curl -sk -b $J -c $J -o /tmp/post.out -w '%{http_code}\n' -H "Origin: $B" -F action=upload -F page=server_settings -F "token_submit=$TOK" -F "server_update_bundle=@$3" "$B/$2" ;;
esac
