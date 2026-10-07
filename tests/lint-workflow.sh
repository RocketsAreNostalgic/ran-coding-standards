#!/usr/bin/env bash
# Exercise the actual workflow shell block, including failed file discovery.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
awk '
  /^      - name: Lint repository PHP$/ { step = 1; next }
  step && /^        run: \|$/ { body = 1; next }
  body && /^          / { sub(/^          /, ""); print; next }
  body && NF { exit }
' "$root/.github/workflows/quality.yml" > "$fixture/lint.sh"
test -s "$fixture/lint.sh"
mkdir -p "$fixture/source/vendor" "$fixture/bin"
printf '<?php echo 1;\n' > "$fixture/source/space name.php"
printf '<?php echo 2;\n' > "$fixture/source/line"$'\n'"break.php"
printf '<?php function ( {\n' > "$fixture/source/vendor/ignored.php"
printf '<?php function ( {\n' > "$fixture/source/ignored.txt"

run_lint() {
  (cd "$fixture/source" && bash "$fixture/lint.sh") > "$fixture/result.log" 2>&1
}
run_lint
printf '<?php function ( {\n' > "$fixture/source/broken.php"
if run_lint; then
  echo 'The workflow accepted invalid PHP.' >&2
  exit 1
fi
if php -l "$fixture/source/broken.php" > "$fixture/parser.log" 2>&1; then
  echo 'The parser control must contain invalid PHP.' >&2
  exit 1
fi
grep -qi 'parse error' "$fixture/parser.log"
rm "$fixture/source/broken.php"
# Only the root vendor role is pruned, not a maintained nested directory.
mkdir -p "$fixture/source/tests/vendor"
printf '<?php function ( {\n' > "$fixture/source/tests/vendor/broken.php"
if run_lint; then
  echo 'The workflow skipped maintained nested vendor PHP.' >&2
  exit 1
fi
rm -rf "$fixture/source/tests"

cat > "$fixture/bin/find" <<'SH'
#!/usr/bin/env bash
if [[ $RAN_DISCOVERY_PROBE == partial ]]; then
  printf '%s\0' './space name.php'
fi
exit 71
SH
chmod +x "$fixture/bin/find"
for probe in empty partial; do
  status=0
  (export PATH="$fixture/bin:$PATH" RAN_DISCOVERY_PROBE="$probe"; run_lint) || status=$?
  if [[ $status != 71 ]]; then
    echo "The workflow lost the $probe discovery failure (status $status)." >&2
    exit 1
  fi
done
echo 'Workflow lint preserves selection and fails on parser and discovery errors.'
