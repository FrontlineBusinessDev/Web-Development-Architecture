#!/usr/bin/env bash
# Send a local image into the server's Docker over SSH, leaving out the layers the server already
# holds (from the release running there, or another app on the same base). A code change usually
# sends only the app's own layer, not the whole image.
#   bash scripts/send-image.sh <image:tag> <user@server>      (scripts/publish.sh runs it)
# The server's layers are listed from Docker's containerd image store (`ctr -n moby`, Docker 29's
# default). On a server without it, or if loading the partial image fails, the whole image is sent.
set -euo pipefail
tag=$1
server=$2
on_server() { ssh -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=8 "$server" "$@"; }

dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT
docker save "$tag" | tar -x -C "$dir"
full=$(du -sm "$dir" | cut -f1)

# The layer blobs this image is made of, then the ones the server already has.
layers=$(grep -o '"Layers":\[[^]]*' "$dir/manifest.json" | grep -o '[0-9a-f]\{64\}' | sort -u)
have=$(printf '%s\n' $layers | on_server "bash -c 'grep -xF -f <(ctr -n moby content ls -q 2>/dev/null | sed s/^sha256://)'" || true)

send() { tar -c -C "$dir" . | gzip -1 | on_server docker load >/dev/null; }

if [ -n "$have" ]; then
    mkdir "$dir/kept"
    (cd "$dir/blobs/sha256" && mv $have ../../kept/)
    part=$(du -sm --exclude=kept "$dir" | cut -f1)
    echo "Sending ${part} MB of ${full} MB: the server has the other layers."
    # The kept blobs go back if the server refuses the partial image (one of its layers was pruned meanwhile).
    tar -c -C "$dir" --exclude=./kept . | gzip -1 | on_server docker load >/dev/null && exit 0
    echo "The server did not take the partial image: sending all ${full} MB."
    mv "$dir/kept/"* "$dir/blobs/sha256/" && rmdir "$dir/kept"
else
    echo "Sending all ${full} MB (the server has none of its layers)."
fi
send
