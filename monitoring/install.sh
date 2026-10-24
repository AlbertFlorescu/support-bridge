#!/usr/bin/env bash
set -euo pipefail
umask 077

# Reviewed, user-local Linux x86_64 binaries; no package manager, sudo or service changes.
monitor_root="${BRIDGE_MONITORING_HOME:-${XDG_DATA_HOME:-$HOME/.local/share}/supportbridge/monitoring}"
if [[ "$(uname -s)" != Linux || "$(uname -m)" != x86_64 ]]; then
  echo 'This pinned installer supports Linux x86_64 (including Ubuntu WSL) only.' >&2
  exit 1
fi
for dependency in curl tar sha256sum python3; do
  command -v "$dependency" >/dev/null || { echo "Missing prerequisite: $dependency" >&2; exit 1; }
done
mkdir -p "$monitor_root/downloads" "$monitor_root/bin"
monitor_root="$(realpath "$monitor_root")"

install_archive() {
  local name="$1" version="$2" url="$3" digest="$4" destination="$monitor_root/bin/$1-$2"
  local archive="$monitor_root/downloads/$name-$version.tar.gz"
  if [[ -f "$destination/.verified-sha256" ]] && [[ "$(cat "$destination/.verified-sha256")" == "$digest" ]]; then
    echo "$name $version already installed from the pinned archive."
    return
  fi
  if [[ -e "$destination" ]]; then
    echo "Refusing to overwrite an unverified installation: $destination" >&2
    exit 1
  fi
  if [[ ! -f "$archive" ]] || ! printf '%s  %s\n' "$digest" "$archive" | sha256sum --check --status; then
    curl --fail --location --proto '=https' --proto-redir '=https' --retry 2 \
      --connect-timeout 15 --max-time 600 --output "$archive.partial" "$url"
    printf '%s  %s\n' "$digest" "$archive.partial" | sha256sum --check --status || {
      echo "Checksum mismatch for $name; no archive was extracted." >&2; exit 1;
    }
    mv -- "$archive.partial" "$archive"
  fi
  # Fail closed on unexpected archive paths before extraction.
  python3 - "$archive" <<'PY'
import pathlib, sys, tarfile
with tarfile.open(sys.argv[1], 'r:gz') as archive:
    for member in archive.getmembers():
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or '..' in path.parts:
            raise SystemExit('Unsafe archive path rejected')
        if member.issym() or member.islnk():
            target = pathlib.PurePosixPath(member.linkname)
            if target.is_absolute() or '..' in target.parts:
                raise SystemExit('Unsafe archive link rejected')
PY
  mkdir "$destination"
  tar -xzf "$archive" --strip-components=1 -C "$destination"
  printf '%s\n' "$digest" > "$destination/.verified-sha256"
  echo "Installed $name $version (SHA-256 verified)."
}

install_archive prometheus 3.13.5 \
  https://github.com/prometheus/prometheus/releases/download/v3.13.5/prometheus-3.13.5.linux-amd64.tar.gz \
  0388822ace519c5a3a8ef556a541ce1b536e21d872f82deded465bd27a5a0699
install_archive grafana 13.2.4 \
  https://dl.grafana.com/grafana/release/13.2.4/grafana_13.2.4_38252365904_linux_amd64.tar.gz \
  c48765988fd88572607e4ea670251af8a8e64ab3e360d0f3cf67a6f3587b92ab
"$monitor_root/bin/prometheus-3.13.5/prometheus" --version
"$monitor_root/bin/grafana-13.2.4/bin/grafana" --version
echo "Installed under $monitor_root. Nothing has been started."
