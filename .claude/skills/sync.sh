#!/usr/bin/env bash
#
# Раскладка навыков линейки shef.* по репозиториям и сверка копий с источником.
#
#   .claude/skills/sync.sh --manifest          пересобрать MANIFEST (в источнике)
#   .claude/skills/sync.sh --check             свои файлы против своего MANIFEST
#   .claude/skills/sync.sh --check <источник>  ещё и сверка с источником
#   .claude/skills/sync.sh --to <путь к репо>  разложить в соседний репозиторий
#   .claude/skills/sync.sh --local             пересобрать LOCAL.MANIFEST (у получателя)
#
# <источник> — путь к MANIFEST источника либо его адрес (raw.githubusercontent).
#
# Зачем это устроено так. Навыки живут в одном месте — в shef.options, — а
# нужны в каждом репозитории линейки: агент читает их из .claude/skills/ того
# репозитория, где работает. Копия без сверки разойдётся с источником за
# месяц, и разойдётся МОЛЧА: навык продолжит уверенно рассказывать про
# переименованный класс. Поэтому копия несёт с собой список хешей и этот
# скрипт, а CI получателя зовёт --check.
#
# Локальные навыки. У получателя бывают навыки про него самого, которых в
# источнике нет и быть не должно: источник не знает о получателях. Они лежат
# рядом с копией и перечислены в LOCAL.MANIFEST получателя (хеши, тот же
# формат). --check сверяет с диском оба манифеста, --check <источник> — только
# копию, а --to локальные навыки не трогает: без этого первая же раскладка
# стирала бы их как «то, чего в источнике нет». Имя локального навыка не
# должно совпадать с навыком линейки — --to откажется раскладывать. Файл, не
# записанный ни в один манифест получателя, --to тоже не удаляет, а
# отказывается: удалять можно только то, что сам когда-то разложил.

set -euo pipefail

SKILLS_DIR="$(cd "$(dirname "$0")" && pwd)"
MANIFEST_NAME='MANIFEST'
MANIFEST="$SKILLS_DIR/$MANIFEST_NAME"
LOCAL_NAME='LOCAL.MANIFEST'
LOCAL="$SKILLS_DIR/$LOCAL_NAME"

RED=''; GREEN=''; YELLOW=''; RESET=''
if [ -t 1 ]; then
	RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
fi

fail() { echo "${RED}[FAIL]${RESET} $*" >&2; }
ok()   { echo "${GREEN}[ OK ]${RESET} $*"; }
note() { echo "${YELLOW}[ .. ]${RESET} $*"; }

# sha256 есть везде, но зовётся по-разному: sha256sum в Linux, shasum в macOS.
hash_file()
{
	if command -v sha256sum >/dev/null 2>&1
	then
		sha256sum "$1" | awk '{print $1}'
	elif command -v shasum >/dev/null 2>&1
	then
		shasum -a 256 "$1" | awk '{print $1}'
	else
		fail 'не нашёл ни sha256sum, ни shasum'
		exit 2
	fi
}

# Строки манифеста без комментариев и пустых.
manifest_lines()
{
	grep -vE '^[[:space:]]*(#|$)' "${1:-$MANIFEST}" 2>/dev/null || true
}

# Пути из манифеста.
manifest_paths()
{
	manifest_lines "$1" | sed -E 's/^[0-9a-f]+  //'
}

# Все файлы каталога, кроме обоих манифестов. Порядок фиксируем сортировкой
# в C-локали, иначе манифест будет разный на разных машинах.
all_files()
{
	( cd "$SKILLS_DIR" && find . -type f ! -name "$MANIFEST_NAME" ! -name "$LOCAL_NAME" -print ) \
		| sed 's#^\./##' \
		| LC_ALL=C sort
}

# Файлы навыков линейки: всё, кроме локальных навыков получателя.
skill_files()
{
	if [ -f "$LOCAL" ]
	then
		LC_ALL=C comm -23 <(all_files) <(manifest_paths "$LOCAL" | LC_ALL=C sort)
	else
		all_files
	fi
}

# Строки в формате манифеста для списка файлов со stdin.
lines_of()
{
	local file
	while IFS= read -r file
	do
		printf '%s  %s\n' "$(hash_file "$SKILLS_DIR/$file")" "$file"
	done
}

# Текущее состояние навыков линейки в формате манифеста.
current_lines()
{
	skill_files | lines_of
}

write_manifest()
{
	local tmp

	# Временный файл — ВНЕ каталога навыков. Перенаправление создаёт его до
	# того, как отработает current_lines, и лежащий рядом файл попал бы в
	# собственный манифест.
	tmp="$(mktemp)"

	{
		echo '# Навыки линейки shef.*'
		echo '#'
		echo '# Источник: https://github.com/bx-shef/options, каталог .claude/skills/'
		echo '# Здесь — копия. Правят навыки ТОЛЬКО в источнике, сюда их раскладывает'
		echo '# sync.sh --to. Правка копии на месте будет затёрта следующей раскладкой,'
		echo '# а до неё — поймана проверкой.'
		echo '#'
		echo '#   .claude/skills/sync.sh --check            целостность копии'
		echo '#   .claude/skills/sync.sh --check <источник> сверка с источником'
		echo '#'
		echo '# Пересобрать в источнике: .claude/skills/sync.sh --manifest'
		echo '#'
		current_lines
	} > "$tmp"

	mv "$tmp" "$MANIFEST"

	ok "манифест пересобран: $(manifest_lines | wc -l | tr -d ' ') файлов"
}

check_self()
{
	local bad=0

	if [ ! -f "$MANIFEST" ]
	then
		fail "манифеста нет: $MANIFEST"
		return 1
	fi

	# Оба манифеста вместе против всего, что на диске: так ловится и правка
	# навыка, и файл, не попавший ни в один манифест.
	local diffOut
	if ! diffOut="$(diff <( { manifest_lines; manifest_lines "$LOCAL"; } | LC_ALL=C sort -k2) <(all_files | lines_of | LC_ALL=C sort -k2))"
	then
		fail 'копия навыков разошлась со своим манифестом'
		echo "$diffOut" | sed 's/^/      /' >&2
		echo '      < в манифесте, > на диске' >&2
		echo '      Правили навык в копии? Правьте в источнике и разложите заново.' >&2
		echo '      Правили в источнике? Пересоберите манифест: sync.sh --manifest' >&2
		echo '      Правили локальный навык получателя? Пересоберите: sync.sh --local' >&2
		bad=1
	fi

	if [ $bad -eq 0 ]
	then
		ok "навыки целы: $(manifest_lines | wc -l | tr -d ' ') файлов, локальных $(manifest_lines "$LOCAL" | wc -l | tr -d ' ')"
	fi

	return $bad
}

check_against()
{
	local source="$1"
	local copy

	copy="$(mktemp)"
	# shellcheck disable=SC2064
	trap "rm -f '$copy'" RETURN

	case "$source" in
		http://*|https://*)
			if ! curl -fsS "$source" -o "$copy"
			then
				fail "не забрал манифест источника: $source"
				return 1
			fi
			;;
		*)
			if [ ! -f "$source" ]
			then
				fail "манифеста источника нет: $source"
				return 1
			fi
			cp "$source" "$copy"
			;;
	esac

	local diffOut
	if ! diffOut="$(diff <(manifest_lines "$copy") <(manifest_lines))"
	then
		fail 'навыки отстали от источника'
		echo "$diffOut" | sed 's/^/      /' >&2
		echo '      < в источнике, > здесь' >&2
		echo '      Разложить заново: <источник>/.claude/skills/sync.sh --to <этот репозиторий>' >&2
		return 1
	fi

	ok 'навыки совпадают с источником'
}

# Локальные навыки получателя: всё на диске, чего нет в копии линейки.
write_local()
{
	local tmp

	if [ ! -f "$MANIFEST" ]
	then
		fail "манифеста нет: $MANIFEST"
		return 1
	fi

	tmp="$(mktemp)"

	{
		echo '# Локальные навыки этого репозитория — их нет в источнике линейки.'
		echo '#'
		echo '# Правят их здесь. sync.sh --to их не трогает, --check сверяет с диском.'
		echo '# Пересобрать: .claude/skills/sync.sh --local'
		echo '#'
		LC_ALL=C comm -23 <(all_files) <(manifest_paths "$MANIFEST" | LC_ALL=C sort) | lines_of
	} > "$tmp"

	mv "$tmp" "$LOCAL"

	ok "локальный манифест пересобран: $(manifest_lines "$LOCAL" | wc -l | tr -d ' ') файлов"
}

sync_to()
{
	local target="$1"

	if [ ! -d "$target" ]
	then
		fail "каталога нет: $target"
		return 1
	fi

	if [ ! -f "$MANIFEST" ]
	then
		fail 'в источнике нет манифеста, пересоберите: sync.sh --manifest'
		return 1
	fi

	# Сверяем источник с его манифестом ДО раскладки: разложить протухшее
	# хуже, чем не разложить.
	if ! check_self >/dev/null
	then
		fail 'источник разошёлся со своим манифестом — сначала sync.sh --manifest'
		return 1
	fi

	local targetDir="$target/.claude/skills"
	local targetLocal="$targetDir/$LOCAL_NAME"
	local file count=0

	mkdir -p "$targetDir"

	# Локальный навык получателя с именем навыка линейки раскладка затёрла бы.
	if [ -f "$targetLocal" ]
	then
		local clash
		clash="$(LC_ALL=C comm -12 <(manifest_paths "$targetLocal" | LC_ALL=C sort) <(skill_files))"
		if [ -n "$clash" ]
		then
			fail 'локальный навык получателя совпадает с навыком линейки:'
			echo "$clash" | sed 's/^/      /' >&2
			return 1
		fi
	fi

	# Файл получателя, которого нет ни в источнике, ни в его MANIFEST (то есть
	# не наш и не разложен нами раньше), ни в его LOCAL.MANIFEST, — чужой, и
	# удалять его нельзя. Так было: локальный навык, заведённый до первого
	# --local, раскладка стирала молча, а файлы ещё не лежали в git.
	local unknown
	unknown="$(LC_ALL=C comm -23 \
		<( cd "$targetDir" && find . -type f ! -name "$MANIFEST_NAME" ! -name "$LOCAL_NAME" -print | sed 's#^\./##' | LC_ALL=C sort ) \
		<( { skill_files; manifest_paths "$targetDir/$MANIFEST_NAME"; manifest_paths "$targetLocal"; } | LC_ALL=C sort -u ))"
	if [ -n "$unknown" ]
	then
		fail 'у получателя файлы, которых нет ни в источнике, ни в его манифестах:'
		echo "$unknown" | sed 's/^/      /' >&2
		echo '      Это локальные навыки? У получателя: .claude/skills/sync.sh --local' >&2
		echo '      Мусор? Удалите руками. Раскладка ничего не тронула.' >&2
		return 1
	fi

	# Убираем то, что разложили раньше, а в источнике его уже нет: иначе
	# удалённый навык останется жить в копии. Локальные — не наши.
	while IFS= read -r file
	do
		if [ ! -f "$SKILLS_DIR/$file" ] && [ -f "$targetDir/$file" ]
		then
			rm -f "$targetDir/$file"
			note "убран файл, которого больше нет в источнике: $file"
		fi
	done < <(manifest_paths "$targetDir/$MANIFEST_NAME")

	while IFS= read -r file
	do
		mkdir -p "$targetDir/$(dirname "$file")"
		cp -p "$SKILLS_DIR/$file" "$targetDir/$file"
		count=$((count + 1))
	done < <(skill_files)

	cp -p "$MANIFEST" "$targetDir/$MANIFEST_NAME"

	{
		echo "# Разложено $(date '+%Y-%m-%d %H:%M:%S') из $(git -C "$SKILLS_DIR" rev-parse --short HEAD 2>/dev/null || echo 'не git')"
	} >> "$targetDir/$MANIFEST_NAME"

	ok "разложено файлов: $count -> $targetDir"
	echo
	echo 'Добавьте в CI получателя:'
	echo "  .claude/skills/sync.sh --check https://raw.githubusercontent.com/bx-shef/options/main/.claude/skills/$MANIFEST_NAME"
}

usage()
{
	sed -n '3,13p' "$0" | sed 's/^# \{0,1\}//'
}

main()
{
	case "${1-}" in
		--manifest)
			write_manifest
			;;
		--check)
			check_self || exit 1
			if [ -n "${2-}" ]
			then
				check_against "$2" || exit 1
			fi
			;;
		--local)
			write_local
			;;
		--to)
			if [ -z "${2-}" ]
			then
				fail 'не указан каталог получателя'
				usage >&2
				exit 2
			fi
			sync_to "$2" || exit 1
			;;
		*)
			usage
			exit 2
			;;
	esac
}

main "$@"
