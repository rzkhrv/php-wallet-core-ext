#!/bin/sh
# OS-specific bootstrap dependencies. POSIX sh is required because Alpine's
# base image has no Bash yet; all later build scripts use Bash.
set -eu

: "${BUILD_OS:?BUILD_OS is required}"
: "${BUILD_OS_VERSION:?BUILD_OS_VERSION is required}"
. /etc/os-release

case "$BUILD_OS" in
  rockylinux) [ "$ID" = rocky ] ;;
  alpine|debian|ubuntu|fedora|almalinux) [ "$ID" = "$BUILD_OS" ] ;;
  *) echo "ERROR: unsupported OS: $BUILD_OS" >&2; exit 2 ;;
esac
case "$BUILD_OS" in
  alpine|rockylinux|almalinux)
    case "$VERSION_ID" in
      "$BUILD_OS_VERSION"|"$BUILD_OS_VERSION".*) ;;
      *) echo "ERROR: OS VERSION_ID=$VERSION_ID does not match $BUILD_OS:$BUILD_OS_VERSION" >&2; exit 2 ;;
    esac ;;
  *) [ "$VERSION_ID" = "$BUILD_OS_VERSION" ] || {
       echo "ERROR: OS VERSION_ID=$VERSION_ID does not match $BUILD_OS:$BUILD_OS_VERSION" >&2; exit 2;
     } ;;
esac

case "$BUILD_OS" in
  debian|ubuntu)
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      ca-certificates curl wget unzip xz-utils git bash make build-essential \
      autoconf automake libtool pkg-config ninja-build cmake \
      clang llvm libclang-rt-dev libc++-dev libc++abi-dev ccache \
      libboost-all-dev libssl-dev zlib1g-dev python3 ruby-full perl \
      bison re2c binutils
    rm -rf /var/lib/apt/lists/* ;;
  alpine)
    apk add --no-cache \
      ca-certificates curl wget unzip xz git bash make build-base \
      autoconf automake libtool pkgconf ninja cmake \
      clang llvm compiler-rt libc++-dev ccache \
      boost-dev openssl-dev zlib-dev python3 ruby perl \
      bison re2c binutils linux-headers ;;
  fedora)
    dnf install -y \
      ca-certificates curl wget which unzip xz git bash make gcc gcc-c++ \
      autoconf automake libtool pkgconf-pkg-config ninja-build cmake \
      clang llvm compiler-rt ccache boost-devel openssl-devel zlib-devel \
      python3 ruby perl bison re2c binutils
    dnf clean all ;;
  rockylinux|almalinux)
    dnf install -y dnf-plugins-core epel-release
    dnf config-manager --set-enabled crb
    dnf install -y \
      ca-certificates curl-minimal wget which unzip xz git bash make gcc gcc-c++ \
      autoconf automake libtool pkgconf-pkg-config ninja-build cmake \
      clang llvm compiler-rt ccache boost-devel openssl-devel zlib-devel \
      python3 ruby perl bison re2c binutils
    dnf clean all ;;
esac

# Ccache's documented compiler-masquerade mode covers upstream's own CMake
# subprojects (Protobuf, gtest, libcheck) as well as PHP's Autoconf compiler
# invocation, without patching upstream build scripts.
mkdir -p /opt/walletcore-ccache/bin
ccache_bin="$(command -v ccache)"
for compiler in clang clang++ cc c++ gcc g++; do
  ln -s "$ccache_bin" "/opt/walletcore-ccache/bin/$compiler"
done
