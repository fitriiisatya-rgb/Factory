# Amor Factory — aplikasi Android

Satu APK untuk semua pengguna HP: Divisi / FG / Admin / Atasan, Driver, dan Toko.
Isinya WebView ke https://factory.amorgroup.id. Semua tampilan dan aturan tetap di
server, jadi update server **tidak** perlu APK baru.

Yang ditangani aplikasi:
- Pilih peran sekali (diingat). Ganti lewat tekan-lama ikon → "Ganti peran / toko",
  atau link "Ganti peran / toko" di halaman login.
- Link Portal Bakery dari WA terbuka di aplikasi (perlu `/.well-known/assetlinks.json`
  di server — `dist/amor-factory-app-link.zip`).
- Foto dari kamera untuk bukti (otomatis diperkecil ke 1600 px; server menerima maks 5 MB).
- `window.print()` → dialog print Android (pilih printer / RawBT untuk thermal / Simpan PDF).
- Download CSV / file Runchise → folder Download.
- Link luar (WA, Maps, telepon) dibuka di aplikasinya sendiri.

## Build

    TOOLS=/path/tools KEYSTORE=/path/amor-factory-release.p12 KEY_ALIAS=amor-factory \
    STORE_PASS=... bash android/build.sh        # -> dist/amor-factory.apk

`build.sh` menjelaskan isi `$TOOLS`. Untuk update APK: naikkan `android:versionCode`
(+1) dan `versionName` di `AndroidManifest.xml` serta `BuildInfo.VERSION`, lalu build
dengan **keystore yang sama**. Keystore hilang = HP harus uninstall dulu sebelum
bisa memasang versi baru, dan sidik jari di `assetlinks.json` harus diganti.
