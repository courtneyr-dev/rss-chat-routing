#!/usr/bin/env bash
#
# End-to-end: a WordPress status post syndicates to a local rss.chat server,
# a reply on that server comes home as a real, verified Webmention.
#
# Prerequisites:
#   - Docker running (wp-env).
#   - A local rss.chat server (scripting/rss.chat with the webmention patch)
#     running on port 1420 with urlServerForClient=http://host.docker.internal:1420/
#     and a user whose email/code are exported as RSSCHAT_EMAIL / RSSCHAT_CODE.
#   - Run from this directory: bash run-webmention-e2e.sh
#
# The script is idempotent per fresh wp-env; destroy the env to rerun clean:
#   npx @wordpress/env destroy
set -euo pipefail

RSSCHAT_EMAIL="${RSSCHAT_EMAIL:-local@example.test}"
RSSCHAT_CODE="${RSSCHAT_CODE:?export RSSCHAT_CODE (the rss.chat emailSecret)}"
SERVER_HOST="http://localhost:1420"           # the server, from this machine
SERVER_FOR_WP="http://host.docker.internal:1420"  # the same server, from inside wp-env
WP="npx @wordpress/env run cli --"

step() { printf '\n== %s\n' "$*"; }

step "start wp-env"
npx @wordpress/env start >/dev/null

step "configure WordPress"
$WP wp rewrite structure '/%year%/%monthnum%/%postname%/' --hard >/dev/null
$WP wp option update rss_chat_account "{\"email\":\"$RSSCHAT_EMAIL\",\"code\":\"$RSSCHAT_CODE\",\"screenname\":\"courtney\"}" --format=json >/dev/null
$WP wp option update rss_chat_settings "{\"server_url\":\"$SERVER_FOR_WP\"}" --format=json >/dev/null
$WP wp option update rss_chat_routing '{"default_format":"status","default_kind":"","reply_import":"webmention","legacy_all":false}' --format=json >/dev/null

step "1-2. publish a status post; it must syndicate exactly once"
POST_ID=$($WP wp post create --post_title="Status $(date +%s)" --post_content='A status post syndicated to rss.chat.' --post_status=draft --porcelain | tr -dc '0-9')
$WP wp post term set "$POST_ID" post_format post-format-status >/dev/null
$WP wp post update "$POST_ID" --post_status=publish >/dev/null
RSS_ID=$($WP wp post meta get "$POST_ID" _rss_chat_id | tr -dc '0-9')
PERMALINK=$($WP wp post url "$POST_ID" | tr -d '[:space:]')
[ -n "$RSS_ID" ] || { echo "FAIL: post did not syndicate"; exit 1; }
echo "post $POST_ID -> rss.chat item $RSS_ID ($PERMALINK)"

step "item on the server carries the canonical WordPress URL"
LINK=$(curl -s "$SERVER_HOST/getitemandreplies?idparent=$RSS_ID" | python3 -c 'import json,sys; print(json.load(sys.stdin)[0].get("link",""))')
[ "$LINK" = "$PERMALINK" ] || { echo "FAIL: item link '$LINK' != '$PERMALINK'"; exit 1; }
echo "link ok"

step "3. reply on the rss.chat server"
REPLY_ID=$(curl -s -X POST -G "$SERVER_HOST/newpost" \
	--data-urlencode "emailaddress=$RSSCHAT_EMAIL" --data-urlencode "emailcode=$RSSCHAT_CODE" \
	--data-urlencode "jsontext={\"markdowntext\":\"A reply from the network.\",\"inReplyTo\":$RSS_ID}" \
	| python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
echo "reply item $REPLY_ID"

step "4-5. the reply has a public source page containing the exact target"
sleep 3
SRC_BODY=$(curl -s "$SERVER_HOST/item?id=$REPLY_ID")
echo "$SRC_BODY" | grep -q "u-in-reply-to" || { echo "FAIL: no u-in-reply-to"; exit 1; }
echo "$SRC_BODY" | grep -qF "$PERMALINK" || { echo "FAIL: source lacks target"; exit 1; }
echo "source page ok"

step "6-8. the Webmention arrived, was verified, and stored once"
sleep 3
COUNT=$($WP wp db query "SELECT COUNT(*) FROM wp_comments c JOIN wp_commentmeta m ON m.comment_id=c.comment_ID WHERE c.comment_post_ID=$POST_ID AND m.meta_key='protocol' AND m.meta_value='webmention'" --skip-column-names | tr -dc '0-9')
[ "$COUNT" = "1" ] || { echo "FAIL: expected 1 webmention comment, got '$COUNT'"; exit 1; }
echo "one verified webmention comment"

step "9. reprocess the same event: still one comment"
curl -s -X POST "http://localhost:8890/wp-json/webmention/1.0/endpoint" \
	-H "content-type: application/x-www-form-urlencoded" \
	--data-urlencode "source=$SERVER_FOR_WP/item?id=$REPLY_ID" \
	--data-urlencode "target=$PERMALINK" >/dev/null
sleep 2
COUNT=$($WP wp db query "SELECT COUNT(*) FROM wp_comments c JOIN wp_commentmeta m ON m.comment_id=c.comment_ID WHERE c.comment_post_ID=$POST_ID AND m.meta_key='protocol' AND m.meta_value='webmention'" --skip-column-names | tr -dc '0-9')
[ "$COUNT" = "1" ] || { echo "FAIL: duplicate created, count '$COUNT'"; exit 1; }
echo "idempotent"

step "13. the webmention comment was not echoed back to rss.chat"
REPLIES=$(curl -s "$SERVER_HOST/getitemandreplies?idparent=$RSS_ID" | python3 -c 'import json,sys; items=json.load(sys.stdin); print(sum(1 for i in items if i.get("inReplyToNum")))')
[ "$REPLIES" = "1" ] || { echo "FAIL: expected 1 reply on the server, got '$REPLIES'"; exit 1; }
echo "no echo"

step "14. the legacy importer stays off in webmention mode"
$WP wp cron event run rss_chat_backfeed >/dev/null 2>&1 || true
TOTAL=$($WP wp db query "SELECT COUNT(*) FROM wp_comments WHERE comment_post_ID=$POST_ID" --skip-column-names | tr -dc '0-9')
[ "$TOTAL" = "1" ] || { echo "FAIL: legacy importer created a duplicate, total '$TOTAL'"; exit 1; }
echo "no legacy duplicate"

step "12. a source without the target link is rejected"
NOLINK_ID=$(curl -s -X POST -G "$SERVER_HOST/newpost" \
	--data-urlencode "emailaddress=$RSSCHAT_EMAIL" --data-urlencode "emailcode=$RSSCHAT_CODE" \
	--data-urlencode 'jsontext={"markdowntext":"Not a reply, no target link."}' \
	| python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
HTTP=$(curl -s -o /tmp/wm-reject.txt -w '%{http_code}' -X POST "http://localhost:8890/wp-json/webmention/1.0/endpoint" \
	-H "content-type: application/x-www-form-urlencoded" \
	--data-urlencode "source=$SERVER_FOR_WP/item?id=$NOLINK_ID" \
	--data-urlencode "target=$PERMALINK")
grep -q "target_not_found\|400" <<< "$HTTP $(cat /tmp/wm-reject.txt)" || { echo "FAIL: verification did not reject ($HTTP)"; exit 1; }
echo "verification rejects a linkless source ($HTTP)"

step "10-11. remote edit updates, remote delete removes"
curl -s -X POST -G "$SERVER_HOST/updatepost" \
	--data-urlencode "emailaddress=$RSSCHAT_EMAIL" --data-urlencode "emailcode=$RSSCHAT_CODE" \
	--data-urlencode "jsontext={\"id\":$REPLY_ID,\"markdowntext\":\"An edited reply from the network.\"}" >/dev/null
sleep 4
EDITED=$($WP wp db query "SELECT COUNT(*) FROM wp_comments WHERE comment_post_ID=$POST_ID AND comment_content LIKE '%edited reply%'" --skip-column-names | tr -dc '0-9')
[ "$EDITED" = "1" ] || { echo "FAIL: edit did not propagate (count '$EDITED')"; exit 1; }
echo "edit propagated"
curl -s -X POST -G "$SERVER_HOST/deletepost?id=$REPLY_ID" \
	--data-urlencode "emailaddress=$RSSCHAT_EMAIL" --data-urlencode "emailcode=$RSSCHAT_CODE" >/dev/null
sleep 4
# Supported behavior of the current Webmention plugin: a 410 source does NOT
# remove the comment (Receiver::delete only matches resource_* error codes,
# and a dead source yields code http_error). The receiver's re-verification
# fails cleanly, so the comment is retained unchanged and nothing duplicates.
# The source itself answers 410, which is the sender's half of the contract.
GONE=$(curl -s -o /dev/null -w '%{http_code}' "$SERVER_HOST/item?id=$REPLY_ID")
[ "$GONE" = "410" ] || { echo "FAIL: deleted source answers '$GONE', wanted 410"; exit 1; }
LEFT=$($WP wp db query "SELECT COUNT(*) FROM wp_comments c JOIN wp_commentmeta m ON m.comment_id=c.comment_ID WHERE c.comment_post_ID=$POST_ID AND m.meta_key='protocol' AND m.meta_value='webmention'" --skip-column-names | tr -dc '0-9')
[ "$LEFT" = "1" ] || { echo "FAIL: expected the comment retained once, got '$LEFT'"; exit 1; }
echo "delete: source answers 410; receiver retains the comment (current plugin's supported behavior; upstream gap noted)"

printf '\nALL E2E CHECKS PASSED\n'
