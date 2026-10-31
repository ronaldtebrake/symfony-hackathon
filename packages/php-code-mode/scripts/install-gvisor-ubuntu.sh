#!/usr/bin/env bash
set -euo pipefail

# Run only on a dedicated Ubuntu 24.04 sandbox host or disposable CI runner.
# This installs Docker/gVisor and restarts Docker. It does not change the default runtime.
if [[ $(id -u) != 0 ]]; then
    echo 'Run this script as root on the dedicated sandbox host.' >&2
    exit 1
fi
source /etc/os-release
if [[ $ID != ubuntu || $VERSION_ID != 24.04 ]]; then
    echo 'This installer supports Ubuntu 24.04. Use the official gVisor instructions elsewhere.' >&2
    exit 1
fi

apt-get update
apt-get install -y ca-certificates curl gnupg
if ! command -v docker >/dev/null; then
    apt-get install -y docker.io
fi
curl -fsSL https://gvisor.dev/archive.key | gpg --dearmor --yes -o /usr/share/keyrings/gvisor-archive-keyring.gpg
printf 'deb [arch=%s signed-by=/usr/share/keyrings/gvisor-archive-keyring.gpg] https://storage.googleapis.com/gvisor/releases release main\n' "$(dpkg --print-architecture)" > /etc/apt/sources.list.d/gvisor.list
apt-get update
apt-get install -y runsc=20261026.0
runsc install
systemctl enable --now docker
systemctl restart docker
runsc --version
docker info --format '{{json .Runtimes.runsc}}'
