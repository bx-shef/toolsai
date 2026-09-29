#!/usr/bin/env bash
#
# Единственная точка входа сборки и проверок. Её же зовёт CI.
#
#   ./build.sh            проверки + архив shef.toolsai.zip
#   ./build.sh --check    только проверки
#   ./build.sh --version  напечатать версию модуля
#
# Файлы модуля лежат в корне репозитория: Composer разворачивает в целевой
# каталог корень пакета целиком и подкаталоги выбирать не умеет. Поэтому здесь
# два списка — что уезжает на портал и что остаётся в репозитории.
#
# ВАЖНО: файл, не попавший ни в один список, роняет сборку. Это единственная
# страховка раскладки «модуль в корне»: без неё новый файл однажды уедет на
# портал молча.
#
# Тот же список продублирован в .gitattributes через export-ignore — он решает,
# что попадёт в Composer-пакет (git archive его соблюдает). Списки обязаны
# совпадать, иначе на портал уедет разное в зависимости от способа установки.
# Сверяется автоматически, см. check_gitattributes.

set -euo pipefail

MODULE_ID='shef.toolsai'

# Уезжает на портал и в Composer-пакет.
#
# cli/ — диагностика и включение из консоли: скрипты гоняют на сервере портала,
# поэтому они едут вместе с модулем. endpoint/ и admin/ — страницы, которые
# открываются заглушками из /bitrix/tools и /bitrix/admin.
SHIP=(
	'.settings.php'
	'CHANGELOG.md'
	'LICENSE'
	'README.md'
	'autoload.php'
	'composer.json'
	'default_option.php'
	'include.php'
	'options.php'
	'options_conf.php'
	'admin/'
	'cli/'
	'endpoint/'
	'install/'
	'lang/'
	'lib/'
)

# Остаётся в репозитории.
#
# docs/ здесь, а не в SHIP: документация сведена в репозиторий, а README из
# поставки ссылается на неё адресами GitHub. В поставке остаётся только сам
# README — как readme пакета: его показывает Packagist.
#
# docker/ — стенд для проверки в Docker и заглушка OpenAI-совместимого API:
# на портал клиента им незачем.
KEEP=(
	'.claude/'
	'.gitattributes'
	'.github/'
	'.gitignore'
	'CLAUDE.md'
	'CONTRIBUTING.md'
	'build.sh'
	'docker/'
	'docs/'
	'examples/'
	'tests/'
)

# ---------------------------------------------------------------------------

RED=''; GREEN=''; YELLOW=''; RESET=''
if [ -t 1 ]; then
	RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
fi

FAILED=0

fail()
{
	echo "${RED}[FAIL]${RESET} $*" >&2
	FAILED=1
}

ok()
{
	echo "${GREEN}[ OK ]${RESET} $*"
}

note()
{
	echo "${YELLOW}[ .. ]${RESET} $*"
}

# Путь начинается с записи-каталога (запись оканчивается на «/»)?
in_dir_entry()
{
	local file="$1"; shift
	local entry
	for entry in "$@"
	do
		if [ "${entry%/}" != "$entry" ] && [ "${file#"$entry"}" != "$file" ]
		then
			return 0
		fi
	done

	return 1
}

# Путь совпадает с записью-файлом точно?
is_exact_entry()
{
	local file="$1"; shift
	local entry
	for entry in "$@"
	do
		if [ "$entry" = "$file" ]
		then
			return 0
		fi
	done

	return 1
}

# Куда относится файл: ship | keep | none | both
#
# Точное совпадение сильнее совпадения по каталогу: так файл из KEEP можно
# положить внутрь каталога из SHIP, не разнося каталоги по разным деревьям.
classify()
{
	local file="$1"
	local exactShip=0 exactKeep=0

	is_exact_entry "$file" "${SHIP[@]}" && exactShip=1
	is_exact_entry "$file" "${KEEP[@]}" && exactKeep=1

	if [ $exactShip -eq 1 ] && [ $exactKeep -eq 1 ]
	then
		echo both; return 0
	fi
	if [ $exactShip -eq 1 ]
	then
		echo ship; return 0
	fi
	if [ $exactKeep -eq 1 ]
	then
		echo keep; return 0
	fi

	if in_dir_entry "$file" "${SHIP[@]}"
	then
		echo ship; return 0
	fi
	if in_dir_entry "$file" "${KEEP[@]}"
	then
		echo keep; return 0
	fi

	echo none
}

# Файлы под контролем git — то же, что увидит git archive.
#
# core.quotePath=false обязателен: иначе git отдаёт путь с не-ASCII именем
# закавыченным и в восьмеричных экранированных байтах («"docs/\320\243..."»),
# и дальше по скрипту такого файла просто нет — ни в списках, ни на диске.
# Остаток случаев (кавычка, перевод строки, обратный слэш в имени) ловит
# check_filenames: там имя остаётся закавыченным и с этим разбирается человек.
tracked_files()
{
	git -c core.quotePath=false ls-files
}

ship_files()
{
	local f
	while IFS= read -r f
	do
		if [ "$(classify "$f")" = 'ship' ]
		then
			printf '%s\n' "$f"
		fi
	done < <(tracked_files)
}

get_version()
{
	php -r '
		$arModuleVersion = [];
		require __DIR__."/install/version.php";
		echo (string)($arModuleVersion["VERSION"] ?? "");
	'
}

get_version_date()
{
	php -r '
		$arModuleVersion = [];
		require __DIR__."/install/version.php";
		echo (string)($arModuleVersion["VERSION_DATE"] ?? "");
	'
}

# Текстовый файл? (пустой считаем текстовым)
is_text()
{
	[ -s "$1" ] || return 0
	LC_ALL=C grep -qI . "$1" 2>/dev/null
}

# sha256 файла. На Linux считает sha256sum, на macOS — shasum: инструмент
# зависит от системы, а печатаемый хеш от неё зависеть не должен.
sha256_of()
{
	if command -v sha256sum >/dev/null 2>&1
	then
		sha256sum "$1" | cut -d' ' -f1
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

# region Проверки ////

# Каждый файл репозитория должен попасть ровно в один список.
check_lists()
{
	local f verdict bad=0

	while IFS= read -r f
	do
		verdict="$(classify "$f")"
		case "$verdict" in
			ship|keep) ;;
			both)
				fail "файл числится и в SHIP, и в KEEP: $f"
				bad=1
				;;
			*)
				fail "файл не числится ни в SHIP, ни в KEEP: $f"
				bad=1
				;;
		esac
	done < <(tracked_files)

	if [ $bad -eq 0 ]
	then
		ok "списки SHIP/KEEP покрывают все $(tracked_files | wc -l | tr -d ' ') файлов"
	else
		echo "      Допишите файл в SHIP (уезжает на портал) или в KEEP (остаётся здесь)," >&2
		echo "      и продублируйте KEEP в .gitattributes через export-ignore." >&2
	fi
}

# Списки в .gitattributes обязаны совпадать со списком KEEP.
check_gitattributes()
{
	local entry pattern bad=0

	if [ ! -f .gitattributes ]
	then
		fail '.gitattributes не найден'
		return
	fi

	for entry in "${KEEP[@]}"
	do
		pattern="${entry%/}"
		if ! grep -qE "^/?${pattern//./\\.}(/)?[[:space:]]+export-ignore([[:space:]]|$)" .gitattributes
		then
			fail "в .gitattributes нет export-ignore для KEEP-записи: $entry"
			bad=1
		fi
	done

	# Обратная сторона: export-ignore на файле из SHIP увёл бы его из
	# Composer-пакета, оставив в zip. Ровно то расхождение, ради которого
	# списки и сверяются.
	while IFS= read -r pattern
	do
		[ -n "$pattern" ] || continue
		pattern="${pattern#/}"
		if ! is_exact_entry "$pattern" "${KEEP[@]}" && ! is_exact_entry "$pattern/" "${KEEP[@]}"
		then
			fail "в .gitattributes есть export-ignore, которого нет в KEEP: $pattern"
			bad=1
		fi
	done < <(grep -vE '^[[:space:]]*#' .gitattributes \
		| grep -E '[[:space:]]export-ignore([[:space:]]|$)' \
		| awk '{print $1}')

	if [ $bad -eq 0 ]
	then
		ok ".gitattributes совпадает со списком KEEP (${#KEEP[@]} записей)"
	fi
}

# Имя файла, с которым скрипт не справится, и симлинк, который подменяет
# поставку.
#
# Симлинк опасен тем, что каналы поставки расходятся МОЛЧА: cp в build_archive
# разыменовывает ссылку и кладёт в zip содержимое цели, а git archive кладёт
# саму ссылку. Обе сверки состава при этом зелёные — они сверяют имена. Так в
# поставку уезжает файл, которого нет ни в одном списке: хоть KEEP-документ,
# хоть /etc/hostname.
check_filenames()
{
	local f mode bad=0

	while IFS= read -r f
	do
		case "$f" in
			'"'*)
				fail "имя файла со спецсимволами, скрипт с ним не справится: $f"
				bad=1
				;;
		esac
	done < <(tracked_files)

	# git ls-files -s: «<права> <sha> <стадия>\t<путь>», симлинк — 120000.
	while IFS= read -r f
	do
		fail "символическая ссылка под контролем git: $f"
		bad=1
	done < <(git -c core.quotePath=false ls-files -s | awk '$1 == "120000" { sub(/^[^\t]*\t/, ""); print }')

	if [ $bad -eq 0 ]
	then
		ok 'имена файлов обычные, символических ссылок нет'
	fi
}

# Модуль поставляется в UTF-8: установщик кодировку не правит, и файл в CP1251
# доехал бы до портала мусором. Дешевле не пустить его в репозиторий.
check_encoding()
{
	local f bad=0

	while IFS= read -r f
	do
		is_text "$f" || continue

		if ! iconv -f UTF-8 -t UTF-8 "$f" >/dev/null 2>&1
		then
			fail "файл не в UTF-8: $f"
			bad=1
			continue
		fi

		if [ "$(LC_ALL=C head -c 3 "$f" | od -An -tx1 | tr -d ' \n')" = 'efbbbf' ]
		then
			fail "BOM в начале файла: $f"
			bad=1
		fi
	done < <(tracked_files)

	if [ $bad -eq 0 ]
	then
		ok 'все текстовые файлы в UTF-8, без BOM'
	fi
}

# php -l гоняем настройками по умолчанию: на боевом short_open_tag выключен,
# и файл с «<?» там отдался бы в браузер исходником.
check_php()
{
	local f count=0 bad=0

	while IFS= read -r f
	do
		case "$f" in *.php) ;; *) continue ;; esac
		count=$((count + 1))
		if ! php -d short_open_tag=Off -l "$f" >/dev/null 2>&1
		then
			fail "php -l: $f"
			php -d short_open_tag=Off -l "$f" 2>&1 | sed 's/^/      /' >&2 || true
			bad=1
		fi
	done < <(tracked_files)

	if [ $bad -eq 0 ]
	then
		ok "php -l прошёл по $count файлам"
	fi
}

# Короткий тег «<?» при short_open_tag=Off (значение по умолчанию) открывающим
# тегом не считается: файл целиком становится инлайновым HTML, классы в нём не
# определяются, а исходник уезжает в браузер. php -l это пропускает — файл
# синтаксически «правильный», просто в нём нет PHP.
#
# Спрашиваем сам PHP, а не grep: «<?» внутри строки или комментария лежит в
# своём токене, а опасный — остаётся куском T_INLINE_HTML. Наивный grep
# краснел бы на каждом регулярном выражении вида /<?/ в чужом коде.
check_short_tags()
{
	local f lines bad=0

	while IFS= read -r f
	do
		case "$f" in *.php) ;; *) continue ;; esac

		lines="$(php -d short_open_tag=Off -r '
			$src = file_get_contents($argv[1]);
			foreach(token_get_all($src) as $token)
			{
				if(is_array($token) && $token[0] === T_INLINE_HTML && str_contains($token[1], "<?"))
				{
					echo $token[2], " ";
				}
			}
		' "$f" 2>/dev/null)"

		if [ -n "$lines" ]
		then
			fail "короткий тег «<?» (нужен «<?php»): $f, строки: ${lines% }"
			bad=1
		fi
	done < <(tracked_files)

	if [ $bad -eq 0 ]
	then
		ok 'коротких тегов «<?» нет'
	fi
}

# Пропущенная проверка выглядит как пройденная — поэтому отсутствие node
# роняет сборку, но только если проверять есть что. Своего JS в модуле сейчас
# нет; появится — CI обязан его проверять, а не молча зеленеть.
check_js()
{
	local f count=0 bad=0 total=0

	total="$(tracked_files | grep -c '\.js$' || true)"

	if [ "$total" -eq 0 ]
	then
		ok 'JS в репозитории нет — проверять нечего'
		return
	fi

	if ! command -v node >/dev/null 2>&1
	then
		fail "node не найден, а JS в репозитории есть ($total файлов)"
		return
	fi

	while IFS= read -r f
	do
		case "$f" in *.js) ;; *) continue ;; esac
		count=$((count + 1))
		if ! node --check "$f" >/dev/null 2>&1
		then
			fail "node --check: $f"
			node --check "$f" 2>&1 | sed 's/^/      /' >&2 || true
			bad=1
		fi
	done < <(tracked_files)

	if [ $bad -eq 0 ]
	then
		ok "node --check прошёл по $count файлам"
	fi
}

# registerNamespace отображает класс в путь строчными: Foo\Bar ищется как
# lib/foo/bar.php. На macOS заглавная буква сходит с рук, на боевом Linux — нет.
check_lowercase()
{
	local f bad=0

	while IFS= read -r f
	do
		case "$f" in lib/*) ;; *) continue ;; esac
		if [ "$f" != "$(printf '%s' "$f" | tr '[:upper:]' '[:lower:]')" ]
		then
			fail "заглавные буквы в пути lib/: $f"
			bad=1
		fi
	done < <(tracked_files)

	if [ $bad -eq 0 ]
	then
		ok 'в lib/ все имена строчными'
	fi
}

check_version()
{
	local version date

	if [ ! -f install/version.php ]
	then
		fail 'install/version.php не найден'
		return
	fi

	version="$(get_version)"
	date="$(get_version_date)"

	if [ -z "$version" ]
	then
		fail 'в install/version.php пустой VERSION'
		return
	fi

	if ! printf '%s' "$version" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'
	then
		fail "VERSION не вида X.Y.Z: $version"
		return
	fi

	if [ -z "$date" ]
	then
		fail 'в install/version.php пустой VERSION_DATE'
		return
	fi

	ok "версия $version от $date"
}

# Тест, который не гоняли, не защищает ничего, а выглядит как пройденный.
# Поэтому здесь сначала считаем, сколько тестов ЕСТЬ, и лишь потом гоняем:
# несовпадение числа — отказ, а не примечание.
run_tests()
{
	local t found=0 count=0 bad=0 hasMjs=0

	for t in tests/*_test.php tests/*_test.mjs
	do
		[ -e "$t" ] || continue
		found=$((found + 1))
		case "$t" in *.mjs) hasMjs=1 ;; esac
	done

	if [ $found -eq 0 ]
	then
		fail 'в tests/ не найдено ни одного *_test.php или *_test.mjs'
		return
	fi

	if [ $hasMjs -eq 1 ] && ! command -v node >/dev/null 2>&1
	then
		fail 'node не найден, а тесты *_test.mjs в репозитории есть'
		return
	fi

	for t in tests/*_test.php
	do
		[ -e "$t" ] || continue
		count=$((count + 1))
		if ! php "$t"
		then
			fail "тест не прошёл: $t"
			bad=1
		fi
	done

	for t in tests/*_test.mjs
	do
		[ -e "$t" ] || continue
		count=$((count + 1))
		if ! node "$t"
		then
			fail "тест не прошёл: $t"
			bad=1
		fi
	done

	if [ $count -ne $found ]
	then
		fail "найдено тестов $found, а прогнано $count"
		return
	fi

	if [ $bad -eq 0 ]
	then
		ok "тесты прошли: $count"
	fi
}

run_checks()
{
	echo "Проверки $MODULE_ID"
	echo
	check_filenames
	check_lists
	check_gitattributes
	check_encoding
	check_php
	check_short_tags
	check_js
	check_lowercase
	check_version
	run_tests
}

# endregion ////

# region Сборка ////

# Архив содержит каталог модуля целиком: первым уровнем внутри zip — <ID>/,
# иначе при распаковке файлы рассыплются по modules/.
build_archive()
{
	local out="$PWD/$MODULE_ID.zip"
	local stage f topLevel stamp stamps wantStamp modes

	stage="$(mktemp -d)"

	while IFS= read -r f
	do
		mkdir -p "$stage/$MODULE_ID/$(dirname "$f")"
		cp -p "$f" "$stage/$MODULE_ID/$f"
	done < <(ship_files)

	# Архив обязан собираться побайтово одинаково у любого, кто взял тот же
	# коммит. Иначе sha256 не с чем сверять: константа в документации стареет
	# на следующем же выпуске, а «соберите сами и сравните» не работает —
	# у свежего клона другое время файлов, и хеш другой.
	#
	# Расхождение дают три вещи, и снимаются все три:
	#   * время файлов — у клона оно равно времени клонирования;
	#   * права — git хранит только бит выполнения, остальное доделывает umask;
	#   * порядок записей — zip -r кладёт их в порядке обхода каталога.
	#
	# Время берём у коммита: на одном теге оно одно и то же у всех. Формат
	# отдаёт сам git, а не date: у BSD и GNU date разные ключи, а git тут и
	# так нужен. touch и zip обязаны работать в одном часовом поясе — zip
	# кладёт в заголовок ЛОКАЛЬНОЕ время, так что UTC с обеих сторон.
	#
	# Секунды гасим в нули не для красоты: zip хранит время с точностью до
	# двух секунд, и у коммита на нечётной секунде записанное значение
	# отличалось бы от заданного — проверка ниже краснела бы на ровном месте.
	stamp="$(TZ=UTC git log -1 --format=%cd --date=format-local:%Y%m%d%H%M).00"
	find "$stage" -type d -exec chmod 755 {} +
	find "$stage" -type f -exec chmod 644 {} +
	find "$stage" -exec env TZ=UTC touch -h -t "$stamp" {} +

	rm -f "$out"
	# Список файлов — явный и отсортированный: zip -r кладёт записи в порядке
	# обхода каталога, а он свой на каждой машине.
	( cd "$stage" && find "$MODULE_ID" | LC_ALL=C sort | TZ=UTC zip -q -X -@ "$out" )
	rm -rf "$stage"

	# Первый уровень внутри архива — ровно один каталог, и это <ID>/.
	topLevel="$(unzip -Z1 "$out" | cut -d/ -f1 | sort -u)"
	if [ "$topLevel" != "$MODULE_ID" ]
	then
		fail "первый уровень архива не «$MODULE_ID/», а: $(printf '%s' "$topLevel" | tr '\n' ' ')"
		return
	fi

	# Состав архива обязан совпасть со списком SHIP.
	if ! diff <(unzip -Z1 "$out" | grep -v '/$' | sed "s#^$MODULE_ID/##" | sort) \
	          <(ship_files | sort) >/dev/null
	then
		fail 'состав архива разошёлся со списком SHIP'
		diff <(unzip -Z1 "$out" | grep -v '/$' | sed "s#^$MODULE_ID/##" | sort) \
		     <(ship_files | sort) | sed 's/^/      /' >&2 || true
		return
	fi

	# Время во всех записях — одно и то же, и равно времени коммита. Это и
	# есть воспроизводимость, выраженная в том, что можно проверить: уберут
	# нормализацию выше — сюда придёт время файлов рабочей копии, проверка
	# покраснеет, и архив не начнёт молча давать каждому свой sha256.
	#
	# Сверять именно со временем коммита, а не «все записи одинаковые»: в
	# свежем клоне время у всех файлов и так одно — время клонирования, — и
	# проверка на одинаковость пропустила бы сборку без нормализации.
	# Порядок записей и права — вторая и третья составляющие того же sha256.
	# Проверяются так же прямо: порядок обязан совпасть с отсортированным,
	# права — быть ровно теми двумя, что выставлены выше.
	#
	# Сверяем имена БЕЗ завершающего «/» у каталогов: архив собран из вывода
	# find, где у каталога слэша нет. Со слэшем порядок другой, если имя
	# каталога — начало имени соседа: «monolog-pr-html» идёт раньше
	# «monolog-pr-html-admin», а «monolog-pr-html/» — позже («-» меньше «/»).
	# Проверка краснела бы на правильно собранном архиве.
	if ! diff <(unzip -Z1 "$out" | sed 's#/$##') <(unzip -Z1 "$out" | sed 's#/$##' | LC_ALL=C sort) >/dev/null
	then
		fail 'записи архива не отсортированы: порядок зависит от обхода каталога'
		return
	fi

	modes="$(unzip -Z "$out" | grep -oE '^[-d][rwx-]{9}' | sort -u | tr '\n' ' ')"
	if [ "$modes" != '-rw-r--r-- drwxr-xr-x ' ]
	then
		fail "права в архиве: $modes— ожидались только «-rw-r--r--» и «drwxr-xr-x»"
		return
	fi

	wantStamp="$(printf '%s' "$stamp" | tr -d '.')"
	wantStamp="${wantStamp:0:8}.${wantStamp:8}"
	stamps="$(TZ=UTC unzip -Z -T "$out" | grep -oE '[0-9]{8}\.[0-9]{6}' | sort -u)"
	if [ "$stamps" != "$wantStamp" ]
	then
		fail "время записей в архиве — «$(printf '%s' "$stamps" | tr '\n' ' ')», а должно быть «$wantStamp»: архив собирается невоспроизводимо"
		return
	fi

	ok "архив $MODULE_ID.zip: $(unzip -Z1 "$out" | grep -cv '/$') файлов, sha256 $(sha256_of "$out")"
}

# То, что отдаст Composer (git archive), обязано совпасть с тем, что уедет
# в zip. Иначе на портал приедет разное в зависимости от способа установки.
# Сверяем не HEAD, а ИНДЕКС: git write-tree собирает дерево ровно из того, что
# видит git ls-files, — то есть из того же, из чего собран список SHIP. Раньше
# здесь стоял HEAD, и на грязном дереве сверка молча пропускалась. А локально
# дерево грязное почти всегда, так что единственная проверка, ловящая
# расхождения самого git archive (вложенный .gitattributes, например),
# срабатывала только в CI.
check_composer_package()
{
	local tree

	if ! tree="$(git write-tree 2>/dev/null)"
	then
		fail 'git write-tree не отдал дерево — незавершённое слияние?'
		return
	fi

	if ! diff <(git archive --format=tar "$tree" | tar -tf - | grep -v '/$' | sort) \
	          <(ship_files | sort) >/dev/null
	then
		fail 'состав Composer-пакета (git archive) разошёлся со списком SHIP'
		diff <(git archive --format=tar "$tree" | tar -tf - | grep -v '/$' | sort) \
		     <(ship_files | sort) | sed 's/^/      /' >&2 || true
		return
	fi

	ok 'состав Composer-пакета совпадает со списком SHIP'
}

# endregion ////

main()
{
	cd "$(dirname "$0")"

	if [ $# -gt 1 ]
	then
		echo "Лишние аргументы: ${*:2}" >&2
		echo "Использование: $0 [--check|--version]" >&2
		return 2
	fi

	case "${1-}" in
		--version)
			get_version
			echo
			return 0
			;;
		--check)
			run_checks
			check_composer_package
			;;
		'')
			run_checks
			check_composer_package
			echo
			build_archive
			;;
		*)
			echo "Неизвестный аргумент: $1" >&2
			echo "Использование: $0 [--check|--version]" >&2
			return 2
			;;
	esac

	echo
	if [ $FAILED -ne 0 ]
	then
		echo "${RED}Проверки не прошли.${RESET}" >&2
		return 1
	fi

	echo "${GREEN}Готово.${RESET}"
}

main "$@"
