#!/usr/bin/env bash
#
# Combination matrix: every named plugin active at once — RSS Chat, RSS Chat
# Routing, Webmention, ActivityPub, IndieWeb, ATmosphere, IndieAuth, Micropub,
# Post Formats for Block Themes, Post Kinds for IndieWeb — with the real
# plugins doing their real jobs.
#
# Needs the local rss.chat server from ../run-webmention-e2e.sh running.
set -euo pipefail

RSSCHAT_EMAIL="${RSSCHAT_EMAIL:-local@example.test}"
RSSCHAT_CODE="${RSSCHAT_CODE:?export RSSCHAT_CODE}"
SERVER_HOST="http://localhost:1420"
SERVER_FOR_WP="http://host.docker.internal:1420"
WP="npx @wordpress/env run cli --"

step() { printf '\n== %s\n' "$*"; }
rss_id() { $WP wp post meta get "$1" _rss_chat_id 2>/dev/null | tr -dc '0-9'; }

publish() { # title, format ('' = none), kind ('' = none), override ('' = none)
	local id
	id=$($WP wp post create --post_title="$1" --post_content='Combo body.' --post_status=draft --porcelain | tr -dc '0-9')
	[ -n "$2" ] && $WP wp post term set "$id" post_format "post-format-$2" >/dev/null
	[ -n "$3" ] && $WP wp post term set "$id" kind "$3" >/dev/null
	[ -n "$4" ] && $WP wp post meta update "$id" _rss_chat_routing "$4" >/dev/null
	$WP wp post update "$id" --post_status=publish >/dev/null
	echo "$id"
}

step "configure"
$WP wp rewrite structure '/%year%/%monthnum%/%postname%/' --hard >/dev/null
$WP wp option update rss_chat_account "{\"email\":\"$RSSCHAT_EMAIL\",\"code\":\"$RSSCHAT_CODE\",\"screenname\":\"courtney\"}" --format=json >/dev/null
$WP wp option update rss_chat_settings "{\"server_url\":\"$SERVER_FOR_WP\"}" --format=json >/dev/null
$WP wp option update rss_chat_routing '{"default_format":"status","default_kind":"note","reply_import":"webmention","legacy_all":false}' --format=json >/dev/null
$WP wp term list kind --fields=slug 2>/dev/null | grep -q note || $WP wp term create kind Note --slug=note >/dev/null

step "format default (real PFBT active): status post syndicates"
P1=$(publish "Combo status $(date +%s)" status "" "")
[ -n "$(rss_id "$P1")" ] || { echo "FAIL: status post did not syndicate"; exit 1; }
echo "ok (item $(rss_id "$P1"))"

step "kind default (real PKIW taxonomy): note-kind post syndicates, format untouched"
P2=$(publish "Combo note $(date +%s)" "" note "")
[ -n "$(rss_id "$P2")" ] || { echo "FAIL: note-kind post did not syndicate"; exit 1; }
echo "ok (item $(rss_id "$P2"))"

step "neither matches: aside post stays home"
P3=$(publish "Combo aside $(date +%s)" aside "" "")
[ -z "$(rss_id "$P3")" ] || { echo "FAIL: aside post syndicated"; exit 1; }
echo "ok"

step "explicit exclude beats a matching format"
P4=$(publish "Combo excluded $(date +%s)" status "" exclude)
[ -z "$(rss_id "$P4")" ] || { echo "FAIL: excluded post syndicated"; exit 1; }
echo "ok"

step "reply comes home as a verified webmention with AP + ATmosphere active"
R1=$(rss_id "$P1")
curl -s -X POST -G "$SERVER_HOST/newpost" \
	--data-urlencode "emailaddress=$RSSCHAT_EMAIL" --data-urlencode "emailcode=$RSSCHAT_CODE" \
	--data-urlencode "jsontext={\"markdowntext\":\"Combo reply.\",\"inReplyTo\":$R1}" >/dev/null
sleep 6
CID=$($WP wp db query "SELECT c.comment_ID FROM wp_comments c JOIN wp_commentmeta m ON m.comment_id=c.comment_ID WHERE c.comment_post_ID=$P1 AND m.meta_key='protocol' AND m.meta_value='webmention' LIMIT 1" --skip-column-names | tr -dc '0-9')
[ -n "$CID" ] || { echo "FAIL: no verified webmention comment"; exit 1; }
echo "webmention comment $CID"

step "the imported reply is NOT federated to ActivityPub"
FED=$($WP wp eval "var_export( \\Activitypub\\should_comment_be_federated( $CID ) );" | tr -d '[:space:]')
[ "$FED" = "false" ] || { echo "FAIL: ActivityPub would federate the webmention comment ($FED)"; exit 1; }
echo "ok"

step "the imported reply was NOT echoed back to rss.chat"
REPLIES=$(curl -s "$SERVER_HOST/getitemandreplies?idparent=$R1" | python3 -c 'import json,sys; items=json.load(sys.stdin); print(sum(1 for i in items if i.get("inReplyToNum")))')
[ "$REPLIES" = "1" ] || { echo "FAIL: server has $REPLIES replies"; exit 1; }
echo "ok"

step "deactivate PKIW after the kind default was saved: fails safe"
$WP wp plugin deactivate post-kinds-for-indieweb >/dev/null
P5=$(publish "Combo after PKIW off $(date +%s)" "" "" "")
[ -z "$(rss_id "$P5")" ] || { echo "FAIL: kindless post syndicated with PKIW off"; exit 1; }
P6=$(publish "Combo status after PKIW off $(date +%s)" status "" "")
[ -n "$(rss_id "$P6")" ] || { echo "FAIL: format default broke when PKIW deactivated"; exit 1; }
$WP wp plugin activate post-kinds-for-indieweb >/dev/null
echo "ok"

step "deactivate PFBT: the format default still works (core formats)"
$WP wp plugin deactivate post-formats-for-block-themes >/dev/null
P7=$(publish "Combo status after PFBT off $(date +%s)" status "" "")
[ -n "$(rss_id "$P7")" ] || { echo "FAIL: format default broke when PFBT deactivated"; exit 1; }
$WP wp plugin activate post-formats-for-block-themes >/dev/null
echo "ok"

printf '\nALL COMBO CHECKS PASSED\n'
