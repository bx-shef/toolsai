#!/usr/bin/env bash
#
# Тестовый входящий звонок с записью — как от внешней АТС.
#
#   test-call.sh <адрес вебхука> [lead|deal]
#
# lead (по умолчанию): лид с телефоном -> звонок, привязанный к лиду.
# deal: контакт + сделка -> звонок, привязанный к контакту; целью Копилота
#       станет сделка контакта (TargetResolver берёт только Deal и Lead).
#
# Порядок как у АТС: register -> finish (DURATION, STATUS_CODE 200) ->
# attachRecord. Автозапуск распознавания срабатывает на attachRecord
# (onAfterUpdate дела), см. docs/00-research.md, раздел 1.
#
# Запись — 20 секунд тона, 128 кбит/с, ~320 КБ: проходит пороги
# SuitableAudiosChecker (60 КБ…25 МБ, 10 с…60 мин).
#
# Печатает CALL_ID, ID лида/сделки и ID дела звонка.

set -euo pipefail

HOOK="${1:?адрес вебхука: http://portal/rest/1/xxxx/}"
MODE="${2:-lead}"
HOOK="${HOOK%/}/"
PHONE="+37529$(printf '%07d' $((RANDOM * 30 % 10000000)))"

call()
{
	local method="$1"; shift
	curl -sS -X POST "${HOOK}${method}.json" "$@"
}

json()
{
	php -r '$d = json_decode(stream_get_contents(STDIN), true); $v = $d; foreach(explode(".", $argv[1]) as $k) { $v = $v[$k] ?? null; } if($v === null) { fwrite(STDERR, "нет ".$argv[1]." в ответе: ".json_encode($d, JSON_UNESCAPED_UNICODE)."\n"); exit(1); } echo is_scalar($v) ? $v : json_encode($v);' "$1"
}

if [ "$MODE" = 'deal' ]
then
	CONTACT_ID="$(call crm.contact.add --data-urlencode "fields[NAME]=Стенд $PHONE" --data-urlencode "fields[PHONE][0][VALUE]=$PHONE" --data-urlencode 'fields[PHONE][0][VALUE_TYPE]=WORK' -d 'fields[ASSIGNED_BY_ID]=1' | json result)"
	DEAL_ID="$(call crm.deal.add --data-urlencode "fields[TITLE]=Сделка стенда $PHONE" -d "fields[CONTACT_ID]=$CONTACT_ID" -d 'fields[ASSIGNED_BY_ID]=1' | json result)"
	ENTITY=(-d CRM_ENTITY_TYPE=CONTACT -d "CRM_ENTITY_ID=$CONTACT_ID")
	echo "CONTACT_ID=$CONTACT_ID"
	echo "DEAL_ID=$DEAL_ID"
else
	LEAD_ID="$(call crm.lead.add --data-urlencode "fields[TITLE]=Лид стенда $PHONE" --data-urlencode "fields[PHONE][0][VALUE]=$PHONE" --data-urlencode 'fields[PHONE][0][VALUE_TYPE]=WORK' -d 'fields[ASSIGNED_BY_ID]=1' | json result)"
	ENTITY=(-d CRM_ENTITY_TYPE=LEAD -d "CRM_ENTITY_ID=$LEAD_ID")
	echo "LEAD_ID=$LEAD_ID"
fi

CALL_ID="$(call telephony.externalCall.register -d USER_ID=1 --data-urlencode "PHONE_NUMBER=$PHONE" -d TYPE=2 -d CRM_CREATE=0 -d SHOW=0 -d ADD_TO_CHAT=0 -d "EXTERNAL_CALL_ID=stand-$(date +%s)-$RANDOM" "${ENTITY[@]}" | json result.CALL_ID)"
echo "CALL_ID=$CALL_ID"

call telephony.externalCall.finish -d "CALL_ID=$CALL_ID" -d USER_ID=1 -d DURATION=20 -d STATUS_CODE=200 -d ADD_TO_CHAT=0 | json result >/dev/null

RECORD="$(mktemp --suffix=.mp3)"
ffmpeg -loglevel error -y -f lavfi -i 'sine=frequency=440:duration=20' -ac 1 -b:a 128k "$RECORD"
BODY="$(mktemp)"
{
	printf 'CALL_ID=%s&FILENAME=stand-call.mp3&FILE_CONTENT=' "$CALL_ID"
	base64 -w0 "$RECORD" | php -r 'echo rawurlencode(stream_get_contents(STDIN));'
} > "$BODY"
FILE_ID="$(curl -sS -X POST "${HOOK}telephony.externalCall.attachRecord.json" --data-binary "@$BODY" -H 'Content-Type: application/x-www-form-urlencoded' | json result.FILE_ID)"
rm -f "$RECORD" "$BODY"
echo "FILE_ID=$FILE_ID"

echo "Готово. Дело звонка: SELECT ID, OWNER_TYPE_ID, OWNER_ID, ORIGIN_ID FROM b_crm_act WHERE ORIGIN_ID = 'VI_$CALL_ID';"
