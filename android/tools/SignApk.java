import com.android.apksig.ApkSigner;
import com.android.apksig.ApkVerifier;

import java.io.File;
import java.io.FileInputStream;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.cert.X509Certificate;
import java.util.Collections;

/**
 * Signs (v2 — enough for minSdk 24) and then verifies an APK with Google's apksig library — the
 * same code the SDK's apksigner runs. apksig also 4-byte-aligns stored
 * entries (resources.arsc), which Android 11+ requires.
 *
 * java -cp apksig.jar:. SignApk in.apk out.apk keystore.p12 alias   (passwords from env STORE_PASS / KEY_PASS)
 */
public class SignApk {
    public static void main(String[] a) throws Exception {
        char[] storePass = System.getenv("STORE_PASS").toCharArray();
        String keyPassEnv = System.getenv("KEY_PASS");
        char[] keyPass = keyPassEnv != null ? keyPassEnv.toCharArray() : storePass;
        KeyStore ks = KeyStore.getInstance("PKCS12");
        try (FileInputStream in = new FileInputStream(a[2])) {
            ks.load(in, storePass);
        }
        PrivateKey key = (PrivateKey) ks.getKey(a[3], keyPass);
        X509Certificate cert = (X509Certificate) ks.getCertificate(a[3]);
        ApkSigner.SignerConfig signer = new ApkSigner.SignerConfig.Builder("amor", key, Collections.singletonList(cert)).build();
        new ApkSigner.Builder(Collections.singletonList(signer))
                .setInputApk(new File(a[0]))
                .setOutputApk(new File(a[1]))
                .setMinSdkVersion(24)
                .setV1SigningEnabled(false) // v1 needs JDK internals removed in Java 17+; minSdk 24 only needs v2
                .setV2SigningEnabled(true)
                .build()
                .sign();
        ApkVerifier.Result r = new ApkVerifier.Builder(new File(a[1])).build().verify();
        if (!r.isVerified()) {
            System.err.println("VERIFY FAILED: " + r.getErrors());
            System.exit(1);
        }
        System.out.println("signed + verified (v1=" + r.isVerifiedUsingV1Scheme() + ", v2=" + r.isVerifiedUsingV2Scheme() + ")");
    }
}
