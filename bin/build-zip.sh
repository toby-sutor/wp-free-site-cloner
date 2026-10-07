#!/bin/sh
# Builds build/wp-free-site-cloner-<version>.zip from a clean `git archive`
# of HEAD (so .gitattributes export-ignore rules apply and untracked or
# ignored files, such as internal/ or vendor/, can never leak in). Prints
# the file list and size, and verifies the result has no tests/, internal/,
# vendor/, bin/, .github/, .claude/ or node_modules/ paths, no .git* entries,
# no composer, package or phpunit config files and no top-level dotfiles.
#
# Usage: bin/build-zip.sh [--allow-dirty]
#   --allow-dirty  skip the "working tree is clean" check. The zip is still
#                   built from HEAD, not from any uncommitted changes.

set -eu

script_dir=$(cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(cd -- "$script_dir/.." && pwd)
cd "$repo_root"

allow_dirty=0
for arg in "$@"; do
	case "$arg" in
		--allow-dirty)
			allow_dirty=1
			;;
		*)
			echo "Unknown argument: $arg" >&2
			echo "Usage: $0 [--allow-dirty]" >&2
			exit 1
			;;
	esac
done

if [ "$allow_dirty" -ne 1 ]; then
	dirty=$(git status --porcelain)
	if [ -n "$dirty" ]; then
		echo "Working tree is dirty; commit or stash changes first, or pass --allow-dirty." >&2
		git status --short >&2
		exit 1
	fi
fi

slug="wp-free-site-cloner"
main_file="$repo_root/${slug}.php"
if [ ! -f "$main_file" ]; then
	echo "Cannot find $main_file" >&2
	exit 1
fi

version=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$main_file" | head -n1 | tr -d '\r[:space:]')
if [ -z "$version" ]; then
	echo "Could not read the Version header from $main_file" >&2
	exit 1
fi

out_dir="$repo_root/build"
zip_name="${slug}-${version}.zip"
zip_path="$out_dir/$zip_name"

mkdir -p "$out_dir"
rm -f "$zip_path"

echo "Building $zip_name from git archive of HEAD ($(git rev-parse --short HEAD))..."
git archive --prefix="${slug}/" --output="$zip_path" HEAD

if [ ! -s "$zip_path" ]; then
	echo "git archive produced an empty or missing file: $zip_path" >&2
	exit 1
fi

size_bytes=$(wc -c < "$zip_path" | tr -d '[:space:]')
file_count=$(unzip -Z1 "$zip_path" | wc -l | tr -d '[:space:]')

echo ""
echo "Built: $zip_path"
echo "Size: $size_bytes bytes"
echo "Files: $file_count"
echo ""
echo "Contents:"
unzip -Z1 "$zip_path"

echo ""
echo "Checking for paths that must not ship..."
# Alternatives: top-level dev dirs, top-level dev/config files, any top-level
# dotfile or dot-directory, any .git* entry or node_modules/ at any depth.
forbidden="^${slug}/(tests|internal|vendor|bin|\\.github|\\.claude|node_modules)(/|$)"
forbidden="${forbidden}|^${slug}/(composer\\.(json|lock)|package[^/]*\\.json|phpunit[^/]*\\.xml[^/]*)$"
forbidden="${forbidden}|^${slug}/\\.[^/]*"
forbidden="${forbidden}|/\\.git[^/]*(/|$)|/node_modules(/|$)"
excluded=$(unzip -Z1 "$zip_path" | grep -E "$forbidden" || true)
if [ -n "$excluded" ]; then
	echo "ERROR: the zip contains excluded paths:" >&2
	echo "$excluded" >&2
	exit 1
fi

echo "OK: no dev, test, vendor, VCS, CI or config paths and no top-level dotfiles in the zip."
