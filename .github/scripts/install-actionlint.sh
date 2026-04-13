#!/usr/bin/env bash
# Cross-platform actionlint binary installer (macOS and Linux)
# Usage: ./install-actionlint.sh [DOWNLOAD_DIR] [VERSION]
# Environment: VERSION can be overridden via $ACTIONLINT_VERSION env var

set -euo pipefail

DOWNLOAD_DIR="${1:-.github/scripts}"
VERSION="${2:-${ACTIONLINT_VERSION:-1.7.12}}"

# Detect OS and architecture
OS=$(uname -s)
ARCH=$(uname -m)

# Map to actionlint release naming
case "$OS" in
  Darwin)
    case "$ARCH" in
      arm64)
        PLATFORM="darwin_arm64"
        ;;
      x86_64)
        PLATFORM="darwin_amd64"
        ;;
      *)
        echo "❌ Unsupported macOS architecture: $ARCH"
        exit 1
        ;;
    esac
    ;;
  Linux)
    case "$ARCH" in
      x86_64)
        PLATFORM="linux_amd64"
        ;;
      aarch64)
        PLATFORM="linux_arm64"
        ;;
      *)
        echo "❌ Unsupported Linux architecture: $ARCH"
        exit 1
        ;;
    esac
    ;;
  *)
    echo "❌ Unsupported OS: $OS"
    exit 1
    ;;
esac

echo "📦 Installing actionlint $VERSION for $OS/$ARCH..."

TARBALL="actionlint_${VERSION}_${PLATFORM}.tar.gz"
URL="https://github.com/rhysd/actionlint/releases/download/v${VERSION}/${TARBALL}"
CHECKSUMS_URL="https://github.com/rhysd/actionlint/releases/download/v${VERSION}/actionlint_${VERSION}_checksums.txt"

# Clean up any existing files
rm -f "$DOWNLOAD_DIR/actionlint" "$DOWNLOAD_DIR/$TARBALL" "$DOWNLOAD_DIR/checksums.txt"

# Download
echo "⬇️  Downloading $TARBALL..."
curl -fsSL -o "$DOWNLOAD_DIR/$TARBALL" "$URL"

# Download and verify checksum
echo "🔐 Verifying checksum..."
curl -fsSL -o "$DOWNLOAD_DIR/checksums.txt" "$CHECKSUMS_URL"

# Extract the expected checksum for this platform
EXPECTED_CHECKSUM=$(grep " $TARBALL\$" "$DOWNLOAD_DIR/checksums.txt" | awk '{print $1}')
if [[ -z "$EXPECTED_CHECKSUM" ]]; then
  echo "❌ Checksum for $TARBALL not found in checksums.txt"
  exit 1
fi

# Compute actual checksum (use shasum on macOS, sha256sum on Linux)
ACTUAL_CHECKSUM=$(cd "$DOWNLOAD_DIR" && shasum -a 256 "$TARBALL" 2>/dev/null | awk '{print $1}' || sha256sum "$TARBALL" | awk '{print $1}')

if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
  echo "❌ Checksum mismatch for $TARBALL"
  echo "   Expected: $EXPECTED_CHECKSUM"
  echo "   Actual:   $ACTUAL_CHECKSUM"
  exit 1
fi
echo "✅ Checksum verified"

# Extract and verify
echo "📂 Extracting..."
tar -xzf "$DOWNLOAD_DIR/$TARBALL" -C "$DOWNLOAD_DIR" actionlint
chmod +x "$DOWNLOAD_DIR/actionlint"

# Clean up
rm -f "$DOWNLOAD_DIR/$TARBALL" "$DOWNLOAD_DIR/checksums.txt"

# Verify version
echo "✅ Installation complete:"
"$DOWNLOAD_DIR/actionlint" -version
