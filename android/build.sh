#!/usr/bin/env bash
# Builds dist/amor-factory.apk without Android Studio / Gradle:
#   aapt2 (resources + manifest) -> javac -> d8 (dex) -> apksig (sign v2, align, verify)
#
# Needs, in $TOOLS:
#   aapt2            Android aapt2 (Linux x86-64)
#   android-all.jar  Android 14 framework (org.robolectric:android-all, Maven Central) — javac/d8 classpath
#   android-res.zip  AndroidManifest.xml + resources.arsc taken from android-all.jar — aapt2 -I
#   r8.jar           Google R8/D8 (storage.googleapis.com/r8-releases)
#   apksig.jar       com.android.tools.build:apksig (Maven Central)
# and the signing key: $KEYSTORE (PKCS12), $KEY_ALIAS, $STORE_PASS.
# The keystore is NOT in the repo: losing it means phones cannot update the
# app (they would have to uninstall first), so keep it safe.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$HERE/../dist/amor-factory.apk"
B="$HERE/build"
: "${TOOLS:?set TOOLS}" "${KEYSTORE:?set KEYSTORE}" "${KEY_ALIAS:?set KEY_ALIAS}" "${STORE_PASS:?set STORE_PASS}"
export STORE_PASS

V_MANIFEST="$(grep -o 'android:versionName="[^"]*"' "$HERE/AndroidManifest.xml" | cut -d'"' -f2)"
V_JAVA="$(grep -o 'VERSION = "[^"]*"' "$HERE/src/id/amorgroup/factory/BuildInfo.java" | cut -d'"' -f2)"
[ "$V_MANIFEST" = "$V_JAVA" ] || { echo "REFUSING TO BUILD: versionName $V_MANIFEST != BuildInfo.VERSION $V_JAVA"; exit 1; }

rm -rf "$B" && mkdir -p "$B/gen" "$B/classes" "$B/dex"

echo "--- aapt2 compile + link ---"
"$TOOLS/aapt2" compile --dir "$HERE/res" -o "$B/res.zip"
"$TOOLS/aapt2" link -I "$TOOLS/android-res.zip" --manifest "$HERE/AndroidManifest.xml" -A "$HERE/assets" \
  --min-sdk-version 24 --target-sdk-version 34 --java "$B/gen" -o "$B/base.apk" "$B/res.zip"
# aapt2 must only have produced resources: nothing executable may come from it.
if unzip -Z1 "$B/base.apk" | grep -vE '^(AndroidManifest\.xml|resources\.arsc|res/.*|assets/.*)$'; then
  echo "REFUSING TO BUILD: unexpected entry from aapt2"; exit 1
fi

echo "--- javac ---"
javac -nowarn -source 11 -target 11 -encoding UTF-8 -cp "$TOOLS/android-all.jar" -d "$B/classes" \
  $(find "$HERE/src" "$B/gen" -name '*.java') 2>&1 | grep -v "bootstrap class path\|^warning\|^1 warning\|^Note:" || true
[ -f "$B/classes/id/amorgroup/factory/MainActivity.class" ] || { echo "javac failed"; exit 1; }

echo "--- d8 ---"
java -cp "$TOOLS/r8.jar" com.android.tools.r8.D8 --release --min-api 24 --lib "$TOOLS/android-all.jar" \
  --output "$B/dex" $(find "$B/classes" -name '*.class')

echo "--- package + sign ---"
cp "$B/base.apk" "$B/unsigned.apk"
(cd "$B/dex" && zip -q -X "$B/unsigned.apk" classes.dex)
javac -nowarn -cp "$TOOLS/apksig.jar" -d "$B" "$HERE/tools/SignApk.java"
mkdir -p "$(dirname "$OUT")"
java --add-exports java.base/sun.security.x509=ALL-UNNAMED --add-exports java.base/sun.security.pkcs=ALL-UNNAMED --add-exports java.base/sun.security.util=ALL-UNNAMED -cp "$TOOLS/apksig.jar:$B" SignApk "$B/unsigned.apk" "$OUT" "$KEYSTORE" "$KEY_ALIAS"
ls -la "$OUT"
