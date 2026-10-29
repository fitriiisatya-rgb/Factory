package id.amorgroup.factory;

import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.ContentValues;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Matrix;
import android.media.ExifInterface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.ParcelFileDescriptor;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.Toast;

import java.io.InputStream;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;

/**
 * The whole app: one WebView on https://factory.amorgroup.id plus the few
 * things a plain WebView cannot do on its own — choosing the role once,
 * camera photos for evidence uploads, window.print() (A4 and thermal via
 * RawBT), file downloads (CSV / Runchise), and Portal Bakery links from
 * WhatsApp opening inside the app.
 */
public class MainActivity extends Activity {
    static final String HOST = "factory.amorgroup.id";
    static final String BASE = "https://" + HOST;
    static final String LAUNCHER = "file:///android_asset/launcher.html";
    static final String OFFLINE = "file:///android_asset/offline.html";
    static final String ACTION_SWITCH = "id.amorgroup.factory.SWITCH_ROLE";
    static final String PREF_START = "start_url";
    static final int REQ_FILE = 1;
    static final int REQ_STORAGE = 2;
    static final int MAX_PHOTO_SIDE = 1600;

    /** Replaces window.print() (a no-op inside a WebView) with Android printing. */
    static final String PRINT_SHIM = "(function(){if(window.__amorApp)return;window.__amorApp=1;"
            + "window.print=function(){AmorApp.print(document.title||'Amor Factory');};})();";
    /** A small "Ganti peran" link on the login pages, so a shared phone can switch role. */
    static final String SWITCH_LINK = "(function(){if(document.getElementById('amor-app-switch'))return;"
            + "var a=document.createElement('a');a.id='amor-app-switch';a.href='#';a.textContent='⇄ Ganti peran / toko';"
            + "a.style.cssText='display:block;text-align:center;margin:18px auto 28px;color:#93c5fd;font:600 15px system-ui,sans-serif;text-decoration:underline';"
            + "a.onclick=function(e){e.preventDefault();AmorApp.switchRole();};document.body.appendChild(a);})();";

    WebView web;
    ProgressBar progress;
    SharedPreferences prefs;
    ValueCallback<Uri[]> fileCallback;
    Uri cameraUri;
    String[] pendingDownload;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        prefs = getSharedPreferences("amor", MODE_PRIVATE);
        getWindow().setStatusBarColor(0xFF0A1846);
        getWindow().setNavigationBarColor(0xFF0A1846);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(0xFF0A1846);
        web = new WebView(this);
        root.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setMax(100);
        root.addView(progress, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(3)));
        setContentView(root);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setAllowFileAccess(true);
        s.setAllowContentAccess(true);
        s.setJavaScriptCanOpenWindowsAutomatically(true);
        s.setSupportMultipleWindows(false);
        s.setBuiltInZoomControls(true);
        s.setDisplayZoomControls(false);
        s.setUserAgentString(s.getUserAgentString() + " AmorFactoryApp/" + BuildInfo.VERSION);
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);

        web.addJavascriptInterface(new Bridge(), "AmorApp");
        web.setWebViewClient(new Client());
        web.setWebChromeClient(new Chrome());
        web.setDownloadListener((url, ua, disposition, mime, length) -> download(url, ua, disposition, mime));

        if (state != null && web.restoreState(state) != null) {
            return;
        }
        open(getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        open(intent);
    }

    /** Decides what to show for a launch: role picker, a Portal link from WhatsApp, or the saved start page. */
    void open(Intent intent) {
        if (intent != null && ACTION_SWITCH.equals(intent.getAction())) {
            prefs.edit().remove(PREF_START).apply();
            web.loadUrl(LAUNCHER);
            return;
        }
        Uri data = intent != null ? intent.getData() : null;
        if (data != null && isOurs(data)) {
            rememberPortal(data);
            web.loadUrl(data.toString());
            return;
        }
        String start = prefs.getString(PREF_START, null);
        web.loadUrl(start != null ? start : LAUNCHER);
    }

    static boolean isOurs(Uri u) {
        return u != null && "https".equals(u.getScheme()) && HOST.equalsIgnoreCase(u.getHost());
    }

    /**
     * Opening a Portal Bakery link makes that store this phone's start page — unless the
     * phone already belongs to a factory/driver user (an Admin previewing a portal stays Admin).
     */
    void rememberPortal(Uri u) {
        String path = u.getPath() != null ? u.getPath() : "";
        String token = u.getQueryParameter("token");
        String saved = prefs.getString(PREF_START, null);
        boolean storePhone = saved == null || saved.startsWith(BASE + "/api/_store/");
        if (storePhone && path.startsWith("/api/_store") && token != null && !token.isEmpty()) {
            prefs.edit().putString(PREF_START, BASE + "/api/_store/?token=" + Uri.encode(token)).apply();
        }
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }

    @Override
    protected void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) {
            web.goBack();
        } else {
            super.onBackPressed();
        }
    }

    int dp(int v) {
        return Math.round(v * getResources().getDisplayMetrics().density);
    }

    // ------------------------------------------------------------------
    // Page loading
    // ------------------------------------------------------------------

    class Client extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
            Uri u = req.getUrl();
            String scheme = u.getScheme() != null ? u.getScheme() : "";
            if (isOurs(u) || scheme.equals("file")) {
                return false;
            }
            if (scheme.equals("about") || scheme.equals("data") || scheme.equals("blob") || scheme.equals("javascript")) {
                return false;
            }
            // Anything else (WhatsApp, Maps, phone numbers, other sites) opens in its own app.
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, u));
            } catch (ActivityNotFoundException e) {
                Toast.makeText(MainActivity.this, "Tidak ada aplikasi untuk membuka link ini", Toast.LENGTH_SHORT).show();
            }
            return true;
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            Uri u = Uri.parse(url);
            if (isOurs(u)) {
                view.evaluateJavascript(PRINT_SHIM, null);
                rememberPortal(u);
                String path = u.getPath() != null ? u.getPath() : "";
                if (path.startsWith("/api/_admin-login") || path.startsWith("/api/_driver-uat/login")) {
                    view.evaluateJavascript(SWITCH_LINK, null);
                }
            }
            CookieManager.getInstance().flush();
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest req, WebResourceError err) {
            if (req.isForMainFrame()) {
                view.loadUrl(OFFLINE + "#" + Uri.encode(req.getUrl().toString()));
            }
        }
    }

    class Chrome extends WebChromeClient {
        @Override
        public void onProgressChanged(WebView view, int p) {
            progress.setProgress(p);
            progress.setVisibility(p >= 100 ? View.GONE : View.VISIBLE);
            if (p >= 30) {
                view.evaluateJavascript(PRINT_SHIM, null);
            }
        }

        @Override
        public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
            return chooseFile(callback, params);
        }
    }

    // ------------------------------------------------------------------
    // JavaScript bridge (launcher page, offline page, print shim)
    // ------------------------------------------------------------------

    class Bridge {
        @JavascriptInterface
        public void choose(final String url) {
            if (url == null || !url.startsWith(BASE + "/")) {
                return;
            }
            prefs.edit().putString(PREF_START, url).apply();
            runOnUiThread(() -> web.loadUrl(url));
        }

        @JavascriptInterface
        public void switchRole() {
            prefs.edit().remove(PREF_START).apply();
            runOnUiThread(() -> web.loadUrl(LAUNCHER));
        }

        @JavascriptInterface
        public void print(final String title) {
            runOnUiThread(() -> {
                PrintManager pm = (PrintManager) getSystemService(PRINT_SERVICE);
                String job = (title == null || title.isEmpty()) ? "Amor Factory" : title;
                pm.print(job, web.createPrintDocumentAdapter(job), new PrintAttributes.Builder().build());
            });
        }

        @JavascriptInterface
        public String version() {
            return BuildInfo.VERSION;
        }
    }

    // ------------------------------------------------------------------
    // Photos for <input type="file">: camera (resized) or gallery
    // ------------------------------------------------------------------

    boolean chooseFile(ValueCallback<Uri[]> callback, WebChromeClient.FileChooserParams params) {
        if (fileCallback != null) {
            fileCallback.onReceiveValue(null);
        }
        fileCallback = callback;
        cameraUri = null;

        boolean images = false;
        for (String t : params.getAcceptTypes()) {
            if (t == null || t.isEmpty() || t.startsWith("image") || t.equals("*/*")) {
                images = true;
            }
        }
        Intent pick = new Intent(Intent.ACTION_GET_CONTENT);
        pick.addCategory(Intent.CATEGORY_OPENABLE);
        pick.setType(images && onlyImages(params) ? "image/*" : "*/*");
        if (params.getMode() == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) {
            pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
        }

        Intent chooser = Intent.createChooser(pick, "Ambil foto");
        if (images && canSaveToGallery()) {
            Intent camera = cameraIntent();
            if (camera != null) {
                chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[] {camera});
            }
        }
        try {
            startActivityForResult(chooser, REQ_FILE);
        } catch (ActivityNotFoundException e) {
            fileCallback = null;
            callback.onReceiveValue(null);
            return false;
        }
        return true;
    }

    static boolean onlyImages(WebChromeClient.FileChooserParams params) {
        for (String t : params.getAcceptTypes()) {
            if (t != null && !t.isEmpty() && !t.startsWith("image")) {
                return false;
            }
        }
        return true;
    }

    boolean canSaveToGallery() {
        if (Build.VERSION.SDK_INT >= 29) {
            return true;
        }
        if (checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED) {
            return true;
        }
        requestPermissions(new String[] {Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
        return false;
    }

    Intent cameraIntent() {
        ContentValues v = new ContentValues();
        v.put(MediaStore.Images.Media.DISPLAY_NAME, "amor_" + System.currentTimeMillis() + ".jpg");
        v.put(MediaStore.Images.Media.MIME_TYPE, "image/jpeg");
        if (Build.VERSION.SDK_INT >= 29) {
            v.put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/Amor Factory");
        }
        try {
            cameraUri = getContentResolver().insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, v);
        } catch (RuntimeException e) {
            cameraUri = null;
        }
        if (cameraUri == null) {
            return null;
        }
        Intent camera = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
        camera.putExtra(MediaStore.EXTRA_OUTPUT, cameraUri);
        camera.setClipData(ClipData.newRawUri("", cameraUri));
        camera.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION | Intent.FLAG_GRANT_READ_URI_PERMISSION);
        return camera;
    }

    @Override
    protected void onActivityResult(int request, int result, Intent data) {
        if (request != REQ_FILE) {
            super.onActivityResult(request, result, data);
            return;
        }
        if (fileCallback == null) {
            return;
        }
        Uri[] picked = null;
        if (result == RESULT_OK) {
            List<Uri> list = new ArrayList<>();
            if (data != null && data.getClipData() != null) {
                for (int i = 0; i < data.getClipData().getItemCount(); i++) {
                    list.add(data.getClipData().getItemAt(i).getUri());
                }
            } else if (data != null && data.getData() != null) {
                list.add(data.getData());
            }
            if (cameraUri != null && (list.isEmpty() || list.contains(cameraUri)) && hasContent(cameraUri)) {
                // Some camera apps hand the photo back in the result, others only write EXTRA_OUTPUT.
                shrinkPhoto(cameraUri);
                if (!list.contains(cameraUri)) {
                    list.add(cameraUri);
                }
                cameraUri = null;
            }
            if (!list.isEmpty()) {
                picked = list.toArray(new Uri[0]);
            }
        }
        if (cameraUri != null) {
            // Camera was offered but not used: drop the empty gallery entry.
            try {
                getContentResolver().delete(cameraUri, null, null);
            } catch (RuntimeException ignored) {
            }
            cameraUri = null;
        }
        fileCallback.onReceiveValue(picked);
        fileCallback = null;
    }

    boolean hasContent(Uri uri) {
        try (ParcelFileDescriptor fd = getContentResolver().openFileDescriptor(uri, "r")) {
            return fd != null && fd.getStatSize() > 0;
        } catch (Exception e) {
            return false;
        }
    }

    /** Camera photos are often 4–10 MB; the server takes 5 MB. Resize to 1600 px, upright, JPEG 82%. */
    void shrinkPhoto(Uri uri) {
        try {
            BitmapFactory.Options bounds = new BitmapFactory.Options();
            bounds.inJustDecodeBounds = true;
            try (InputStream in = getContentResolver().openInputStream(uri)) {
                BitmapFactory.decodeStream(in, null, bounds);
            }
            int longest = Math.max(bounds.outWidth, bounds.outHeight);
            if (longest <= 0) {
                return;
            }
            int degrees = 0;
            try (InputStream in = getContentResolver().openInputStream(uri)) {
                int o = new ExifInterface(in).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL);
                degrees = o == ExifInterface.ORIENTATION_ROTATE_90 ? 90 : o == ExifInterface.ORIENTATION_ROTATE_180 ? 180 : o == ExifInterface.ORIENTATION_ROTATE_270 ? 270 : 0;
            }
            BitmapFactory.Options opts = new BitmapFactory.Options();
            opts.inSampleSize = 1;
            while (longest / (opts.inSampleSize * 2) >= MAX_PHOTO_SIDE) {
                opts.inSampleSize *= 2;
            }
            Bitmap bmp;
            try (InputStream in = getContentResolver().openInputStream(uri)) {
                bmp = BitmapFactory.decodeStream(in, null, opts);
            }
            if (bmp == null) {
                return;
            }
            float scale = Math.min(1f, (float) MAX_PHOTO_SIDE / Math.max(bmp.getWidth(), bmp.getHeight()));
            Matrix m = new Matrix();
            m.postScale(scale, scale);
            m.postRotate(degrees);
            Bitmap out = Bitmap.createBitmap(bmp, 0, 0, bmp.getWidth(), bmp.getHeight(), m, true);
            try (OutputStream os = getContentResolver().openOutputStream(uri, "wt")) {
                out.compress(Bitmap.CompressFormat.JPEG, 82, os);
            }
        } catch (Exception | OutOfMemoryError ignored) {
            // Keep the original photo; the server will say so if it is too large.
        }
    }

    // ------------------------------------------------------------------
    // Downloads (CSV export, Runchise file, backups)
    // ------------------------------------------------------------------

    void download(String url, String ua, String disposition, String mime) {
        if (Build.VERSION.SDK_INT < 29
                && checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED) {
            pendingDownload = new String[] {url, ua, disposition, mime};
            requestPermissions(new String[] {Manifest.permission.WRITE_EXTERNAL_STORAGE}, REQ_STORAGE);
            return;
        }
        String name = URLUtil.guessFileName(url, disposition, mime);
        DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
        String cookies = CookieManager.getInstance().getCookie(url);
        if (cookies != null) {
            r.addRequestHeader("Cookie", cookies);
        }
        r.addRequestHeader("User-Agent", ua);
        if (mime != null && !mime.isEmpty()) {
            r.setMimeType(mime);
        }
        r.setTitle(name);
        r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
        r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
        ((DownloadManager) getSystemService(DOWNLOAD_SERVICE)).enqueue(r);
        Toast.makeText(this, "Mengunduh " + name + " ke folder Download", Toast.LENGTH_LONG).show();
    }

    @Override
    public void onRequestPermissionsResult(int request, String[] permissions, int[] results) {
        if (request == REQ_STORAGE && pendingDownload != null && results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED) {
            String[] d = pendingDownload;
            pendingDownload = null;
            download(d[0], d[1], d[2], d[3]);
        }
    }
}
