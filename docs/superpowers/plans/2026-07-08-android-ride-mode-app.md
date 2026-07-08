# Android Ride Mode Companion App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Read this before dispatching any task:** unlike this repo's PHP work, most of this plan cannot be headlessly verified by an agent. There is no Android SDK, JDK 17, or Android Studio on this machine as of 2026-07-08 (only JDK 8 was found). Every task's "Manual Verification" step requires a human at Android Studio with a device or emulator — an agent can author every file correctly, but cannot build, install, or watch a notification appear. Flag this plainly instead of claiming a task is "done" when only the code was written.

**Goal:** Build a small native Android app that keeps sending GPS pings to `api-ride-ping.php` via an OS-level foreground service, so Ride Mode tracking survives the rider minimizing the app or locking their screen — something the browser-tab version can never do.

**Architecture:** One Activity (`MainActivity`) hosting a fullscreen `WebView` that loads the existing EasyRide site (login → my-participations → ride-mode). A `JavascriptInterface` bridge lets `ride-mode.php`'s existing tracking JS hand off to a native foreground `RideTrackingService`, which acquires GPS independently via `FusedLocationProviderClient` and POSTs to `api-ride-ping.php` using the same session cookie the WebView already has (read via Android's OS-level `CookieManager`).

**Tech Stack:** Kotlin, Android Gradle Plugin 9.1.0, Gradle 9.1.0, Kotlin 2.2.10, OkHttp 5.4.0, Google Play services location 21.4.0. New, separate repo: `TheoSfak/easyride-android` (public — see Global Constraints).

Full design rationale lives in [docs/superpowers/specs/2026-07-08-android-ride-mode-app-design.md](../specs/2026-07-08-android-ride-mode-app-design.md) — read it first if anything here seems under-explained.

## Global Constraints

- **New repo location:** `C:\Users\user\Desktop\easyride-android` (sibling directory to this repo, `C:\Users\user\Desktop\easyride`), pushed to GitHub as `TheoSfak/easyride-android`.
- **Repo visibility: public.** Required so the app can fetch `server-config.json` from `raw.githubusercontent.com` with a plain unauthenticated HTTPS GET (private-repo raw URLs need an auth token, which must never be embedded in a sideloaded APK). No secrets live in this app's source — all real auth is per-rider session cookies captured at runtime, never baked into the build.
- **`applicationId` / package: `com.easyride.ridemode`.** Deliberately NOT derived from `theeasyride.eu` — the domain is still transitioning to a permanent one, and `applicationId` is effectively permanent once riders have installed the app (changing it later means a new, unrelated app to their phone). `com.easyride.ridemode` stays valid across any future domain change.
- **`minSdk = 26`, `targetSdk = 35`, `compileSdk = 36`.** `targetSdk 35` matches Google Play's current baseline (even though we're sideloading, not chasing bleeding-edge API 36 behavior changes yet is the safer default); `minSdk 26` is the modern floor where notification channels (required for any foreground service) have always existed, so there's no legacy branch to special-case.
- **AGP 9.1.0 / Gradle 9.3.1 / Kotlin 2.2.10.** Task 1's implementer found AGP 9.1.0 actually requires Gradle ≥ 9.3.1 at build time (not 9.1.0 as this plan originally assumed), and needs two opt-out flags in `gradle.properties` — `android.builtInKotlin=false` (AGP 9's built-in Kotlin support otherwise conflicts with the explicitly-applied `kotlin-android` plugin) and `android.newDsl=false` (Kotlin Gradle Plugin 2.2.10 casts to a legacy AGP extension type AGP 9.1's new-DSL default no longer supports). Both are additive build-config flags, not version changes — AGP stays 9.1.0, Kotlin stays 2.2.10, exactly as pinned. **Any task touching `gradle.properties` or `gradle/wrapper/gradle-wrapper.properties` must preserve these.**
- **No CI, no automated Android instrumentation tests in v1** — matches this project's own no-automated-test-framework convention (see the spec's Testing section). The one exception: `ServerConfig`'s pure resolution logic (Task 2) is plain JVM logic with no Android dependency, so it gets real JUnit tests per TDD. Everything else (permissions, foreground service, GPS, WebView bridge) is OS-integration code that can only be meaningfully verified by running it on a device — each task's Testing step says so explicitly.
- **All Greek user-facing strings**, matching the existing web app's language and tone.
- **Never commit signing credentials** — `keystore.properties` and `*.jks` are gitignored from Task 1 onward.

## Prerequisites (do this before Task 1)

1. Install **Android Studio** (bundles JDK 17 and the Android SDK — this machine currently only has JDK 8, which is too old for AGP 9.x). Download from `developer.android.com/studio`.
2. During first-run setup, let it install the Android SDK (API 35 and 36 platforms) and an emulator system image (or have a real Android phone with USB debugging enabled instead).
3. Every `./gradlew` command in this plan needs JDK 17 on `PATH`/`JAVA_HOME`. Android Studio bundles one — point your shell at it before running any Gradle command:
   ```bash
   export JAVA_HOME="/c/Program Files/Android/Android Studio/jbr"
   ```
   (adjust the path if Android Studio installed somewhere else; verify with `"$JAVA_HOME/bin/java" -version` — should report a 17.x build).
4. Confirm `gh` (GitHub CLI) is authenticated (`gh auth status`) — Task 1 creates the new repo with it.

---

### Task 1: Repo scaffold + minimal WebView shell

**Files:**
- Create: `C:\Users\user\Desktop\easyride-android\settings.gradle.kts`
- Create: `C:\Users\user\Desktop\easyride-android\build.gradle.kts`
- Create: `C:\Users\user\Desktop\easyride-android\gradle.properties`
- Create: `C:\Users\user\Desktop\easyride-android\gradle\libs.versions.toml`
- Create: `C:\Users\user\Desktop\easyride-android\gradle\wrapper\gradle-wrapper.properties`
- Create: `C:\Users\user\Desktop\easyride-android\.gitignore`
- Create: `C:\Users\user\Desktop\easyride-android\app\build.gradle.kts`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\AndroidManifest.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\MainActivity.kt`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\layout\activity_main.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\values\strings.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\values\colors.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\values\themes.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\mipmap-anydpi-v26\ic_launcher.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\drawable\ic_launcher_foreground.xml`

**Interfaces:**
- Produces: `MainActivity` class with a `webView: WebView` field, hardcoded to load `BuildConfig.BOOTSTRAP_BASE_URL + "/login.php"` for now — Task 2 replaces the hardcoded load with resolved config.

- [ ] **Step 1: Create the project directory and git repo**

```bash
mkdir -p "C:/Users/user/Desktop/easyride-android"
cd "C:/Users/user/Desktop/easyride-android"
git init
```

- [ ] **Step 2: Write `settings.gradle.kts`**

```kotlin
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "EasyRideRideMode"
include(":app")
```

- [ ] **Step 3: Write `build.gradle.kts` (root)**

```kotlin
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
}
```

- [ ] **Step 4: Write `gradle/libs.versions.toml`**

```toml
[versions]
agp = "9.1.0"
kotlin = "2.2.10"
coreKtx = "1.15.0"
appcompat = "1.7.0"
material = "1.12.0"
playServicesLocation = "21.4.0"
okhttp = "5.4.0"
junit = "4.13.2"

[libraries]
androidx-core-ktx = { group = "androidx.core", name = "core-ktx", version.ref = "coreKtx" }
androidx-appcompat = { group = "androidx.appcompat", name = "appcompat", version.ref = "appcompat" }
material = { group = "com.google.android.material", name = "material", version.ref = "material" }
play-services-location = { group = "com.google.android.gms", name = "play-services-location", version.ref = "playServicesLocation" }
okhttp = { group = "com.squareup.okhttp3", name = "okhttp", version.ref = "okhttp" }
junit = { group = "junit", name = "junit", version.ref = "junit" }

[plugins]
android-application = { id = "com.android.application", version.ref = "agp" }
kotlin-android = { id = "org.jetbrains.kotlin.android", version.ref = "kotlin" }
```

- [ ] **Step 5: Write `gradle.properties`**

```properties
org.gradle.jvmargs=-Xmx2048m -Dfile.encoding=UTF-8
android.useAndroidX=true
kotlin.code.style=official
```

- [ ] **Step 6: Write `gradle/wrapper/gradle-wrapper.properties`**

```properties
distributionBase=GRADLE_USER_HOME
distributionPath=wrapper/dists
distributionUrl=https\://services.gradle.org/distributions/gradle-9.1.0-bin.zip
zipStoreBase=GRADLE_USER_HOME
zipStorePath=wrapper/dists
```

- [ ] **Step 7: Generate the wrapper scripts and jar**

With `JAVA_HOME` set per Prerequisites step 3, and Android Studio's bundled Gradle temporarily on PATH (or any local Gradle ≥ 9.1 install):

```bash
gradle wrapper --gradle-version 9.1.0
```

Expected: creates `gradlew`, `gradlew.bat`, and `gradle/wrapper/gradle-wrapper.jar`. From now on use `./gradlew` (Linux/macOS shell) or `gradlew.bat` (native Windows) for every build command in this plan.

- [ ] **Step 8: Write `.gitignore`**

```gitignore
*.iml
.gradle/
/local.properties
.idea/
.DS_Store
/build/
/app/build/
/captures/
.cxx/
keystore.properties
*.jks
*.keystore
```

- [ ] **Step 9: Write `app/build.gradle.kts`**

```kotlin
plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

android {
    namespace = "com.easyride.ridemode"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.easyride.ridemode"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"

        buildConfigField("String", "BOOTSTRAP_BASE_URL", "\"https://theeasyride.eu\"")
        buildConfigField("String", "SERVER_CONFIG_URL", "\"https://raw.githubusercontent.com/TheoSfak/easyride-android/main/server-config.json\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = false
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.play.services.location)
    implementation(libs.okhttp)
    testImplementation(libs.junit)
}
```

- [ ] **Step 10: Write `app/src/main/AndroidManifest.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <uses-permission android:name="android.permission.INTERNET" />

    <application
        android:allowBackup="true"
        android:icon="@mipmap/ic_launcher"
        android:label="@string/app_name"
        android:theme="@style/Theme.EasyRideRideMode"
        android:usesCleartextTraffic="false">

        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:launchMode="singleTask"
            android:configChanges="orientation|screenSize|keyboardHidden">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

- [ ] **Step 11: Write `app/src/main/res/values/strings.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <string name="app_name">EasyRide Ride Mode</string>
</resources>
```

- [ ] **Step 12: Write `app/src/main/res/values/colors.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">#DC2626</color>
</resources>
```

- [ ] **Step 13: Write `app/src/main/res/values/themes.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <style name="Theme.EasyRideRideMode" parent="Theme.MaterialComponents.DayNight.NoActionBar">
        <item name="colorPrimary">@color/ic_launcher_background</item>
    </style>
</resources>
```

- [ ] **Step 14: Write the launcher icon (vector, no PNG assets needed since minSdk 26 supports adaptive icons exclusively)**

`app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background" />
    <foreground android:drawable="@drawable/ic_launcher_foreground" />
</adaptive-icon>
```

`app/src/main/res/drawable/ic_launcher_foreground.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="108dp"
    android:height="108dp"
    android:viewportWidth="108"
    android:viewportHeight="108">
    <path
        android:fillColor="#FFFFFF"
        android:pathData="M54,24 C68.36,24 80,35.64 80,50 C80,64.36 68.36,76 54,76 C39.64,76 28,64.36 28,50 C28,35.64 39.64,24 54,24 Z M54,34 C45.16,34 38,41.16 38,50 C38,58.84 45.16,66 54,66 C62.84,66 70,58.84 70,50 C70,41.16 62.84,34 54,34 Z" />
</vector>
```

(A plain white ring on the app's brand red — cosmetic placeholder, refine later if wanted.)

- [ ] **Step 15: Write `app/src/main/res/layout/activity_main.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<FrameLayout xmlns:android="http://schemas.android.com/apk/res/android"
    android:layout_width="match_parent"
    android:layout_height="match_parent">

    <WebView
        android:id="@+id/webView"
        android:layout_width="match_parent"
        android:layout_height="match_parent" />

    <ProgressBar
        android:id="@+id/loadingSpinner"
        style="?android:attr/progressBarStyleLarge"
        android:layout_width="wrap_content"
        android:layout_height="wrap_content"
        android:layout_gravity="center" />
</FrameLayout>
```

- [ ] **Step 16: Write `app/src/main/java/com/easyride/ridemode/MainActivity.kt`**

```kotlin
package com.easyride.ridemode

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)

        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, false)

        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true

        val baseUrl = BuildConfig.BOOTSTRAP_BASE_URL
        val baseHost = Uri.parse(baseUrl).host

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val url = request.url
                return if (url.host != null && url.host != baseHost) {
                    startActivity(Intent(Intent.ACTION_VIEW, url))
                    true
                } else {
                    false
                }
            }
        }

        webView.loadUrl("$baseUrl/login.php")

        onBackPressedDispatcher.addCallback(this) {
            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                isEnabled = false
                onBackPressedDispatcher.onBackPressed()
            }
        }
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
    }
}
```

- [ ] **Step 17: Commit**

```bash
cd "C:/Users/user/Desktop/easyride-android"
git add -A
git commit -m "Scaffold Android project: Gradle setup + minimal WebView shell"
```

- [ ] **Step 18: Create and push the GitHub repo**

```bash
gh repo create TheoSfak/easyride-android --public --source=. --remote=origin --push
```

- [ ] **Step 19: Manual verification (Android Studio + device/emulator required)**

1. Open `C:\Users\user\Desktop\easyride-android` in Android Studio, let it sync.
2. Run on a connected device or emulator.
3. Confirm the app opens directly to `theeasyride.eu`'s login page inside the WebView (no browser chrome, no address bar).
4. Log in with a real account; confirm it reaches the normal post-login page.
5. Confirm the app icon shows in the launcher (red circle/ring), and the app is titled "EasyRide Ride Mode".

---

### Task 2: Remote domain configuration (TDD)

**Files:**
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\ServerConfig.kt`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\test\java\com\easyride\ridemode\ServerConfigTest.kt`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\MainActivity.kt`
- Create: `C:\Users\user\Desktop\easyride-android\server-config.json`

**Interfaces:**
- Consumes: `BuildConfig.SERVER_CONFIG_URL`, `BuildConfig.BOOTSTRAP_BASE_URL` (Task 1)
- Produces: `ServerConfig.resolveBaseUrl(fetcher: ConfigFetcher, cache: ConfigCache, bootstrapDefault: String): String` — pure function, later tasks' `MainActivity` calls this via the real `HttpConfigFetcher`/`PrefsConfigCache` implementations also defined here. `MainActivity` gains a `resolvedBaseUrl: String` field set once resolution completes.

- [ ] **Step 1: Write the failing tests**

`app/src/test/java/com/easyride/ridemode/ServerConfigTest.kt`:
```kotlin
package com.easyride.ridemode

import org.junit.Assert.assertEquals
import org.junit.Test

class ServerConfigTest {

    private class FakeFetcher(private val result: String?) : ConfigFetcher {
        override fun fetchBaseUrl(): String? = result
    }

    private class FakeCache(initial: String? = null) : ConfigCache {
        var stored: String? = initial
        override fun getCachedBaseUrl(): String? = stored
        override fun saveBaseUrl(url: String) {
            stored = url
        }
    }

    @Test
    fun fetchSucceeds_returnsFetchedValueAndUpdatesCache() {
        val cache = FakeCache()
        val result = ServerConfig.resolveBaseUrl(
            FakeFetcher("https://fetched.example"), cache, "https://bootstrap.example"
        )
        assertEquals("https://fetched.example", result)
        assertEquals("https://fetched.example", cache.stored)
    }

    @Test
    fun fetchFailsButCacheHasValue_returnsCachedValue() {
        val cache = FakeCache(initial = "https://cached.example")
        val result = ServerConfig.resolveBaseUrl(
            FakeFetcher(null), cache, "https://bootstrap.example"
        )
        assertEquals("https://cached.example", result)
    }

    @Test
    fun fetchFailsAndNoCache_returnsBootstrapDefault() {
        val cache = FakeCache()
        val result = ServerConfig.resolveBaseUrl(
            FakeFetcher(null), cache, "https://bootstrap.example"
        )
        assertEquals("https://bootstrap.example", result)
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail (compile error — `ServerConfig`/`ConfigFetcher`/`ConfigCache` don't exist yet)**

```bash
cd "C:/Users/user/Desktop/easyride-android"
./gradlew testDebugUnitTest --tests "com.easyride.ridemode.ServerConfigTest"
```
Expected: FAILS with an unresolved-reference compile error.

- [ ] **Step 3: Write `ServerConfig.kt`**

```kotlin
package com.easyride.ridemode

import android.content.Context
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

interface ConfigFetcher {
    fun fetchBaseUrl(): String?
}

interface ConfigCache {
    fun getCachedBaseUrl(): String?
    fun saveBaseUrl(url: String)
}

object ServerConfig {
    fun resolveBaseUrl(fetcher: ConfigFetcher, cache: ConfigCache, bootstrapDefault: String): String {
        val fetched = fetcher.fetchBaseUrl()
        if (fetched != null) {
            cache.saveBaseUrl(fetched)
            return fetched
        }
        return cache.getCachedBaseUrl() ?: bootstrapDefault
    }
}

class HttpConfigFetcher(private val configUrl: String) : ConfigFetcher {
    override fun fetchBaseUrl(): String? {
        return try {
            val client = OkHttpClient.Builder()
                .connectTimeout(5, TimeUnit.SECONDS)
                .readTimeout(5, TimeUnit.SECONDS)
                .build()
            val request = Request.Builder().url(configUrl).build()
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                val body = response.body?.string() ?: return null
                val url = JSONObject(body).optString("base_url")
                url.takeIf { it.isNotBlank() }
            }
        } catch (e: Exception) {
            null
        }
    }
}

class PrefsConfigCache(context: Context) : ConfigCache {
    private val prefs = context.getSharedPreferences("easyride_prefs", Context.MODE_PRIVATE)

    override fun getCachedBaseUrl(): String? = prefs.getString(KEY_BASE_URL, null)

    override fun saveBaseUrl(url: String) {
        prefs.edit().putString(KEY_BASE_URL, url).apply()
    }

    companion object {
        private const val KEY_BASE_URL = "cached_base_url"
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
./gradlew testDebugUnitTest --tests "com.easyride.ridemode.ServerConfigTest"
```
Expected: PASS, 3 tests green.

- [ ] **Step 5: Create `server-config.json` at the repo root**

```json
{
    "base_url": "https://theeasyride.eu"
}
```

- [ ] **Step 6: Wire `ServerConfig` into `MainActivity` — replace the full file**

`app/src/main/java/com/easyride/ridemode/MainActivity.kt`:
```kotlin
package com.easyride.ridemode

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ProgressBar
import androidx.appcompat.app.AppCompatActivity
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var loadingSpinner: ProgressBar
    private var resolvedBaseUrl: String = BuildConfig.BOOTSTRAP_BASE_URL

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        loadingSpinner = findViewById(R.id.loadingSpinner)

        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, false)

        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true

        onBackPressedDispatcher.addCallback(this) {
            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                isEnabled = false
                onBackPressedDispatcher.onBackPressed()
            }
        }

        resolveBaseUrlAndLoad()
    }

    private fun resolveBaseUrlAndLoad() {
        val fetcher = HttpConfigFetcher(BuildConfig.SERVER_CONFIG_URL)
        val cache = PrefsConfigCache(applicationContext)

        Executors.newSingleThreadExecutor().execute {
            val url = ServerConfig.resolveBaseUrl(fetcher, cache, BuildConfig.BOOTSTRAP_BASE_URL)
            runOnUiThread {
                resolvedBaseUrl = url
                setUpWebViewClient(url)
                webView.loadUrl("$url/login.php")
                loadingSpinner.visibility = View.GONE
            }
        }
    }

    private fun setUpWebViewClient(baseUrl: String) {
        val baseHost = Uri.parse(baseUrl).host
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val url = request.url
                return if (url.host != null && url.host != baseHost) {
                    startActivity(Intent(Intent.ACTION_VIEW, url))
                    true
                } else {
                    false
                }
            }
        }
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
    }
}
```

- [ ] **Step 7: Commit and push**

```bash
git add -A
git commit -m "Add remote domain-config resolution with TDD unit tests"
git push
```

- [ ] **Step 8: Manual verification**

1. Rebuild and reinstall the app; confirm it still loads `theeasyride.eu`'s login page (now via the resolved config, not the hardcoded constant) — a brief spinner should show first.
2. Edit `server-config.json` in the pushed GitHub repo to point at a harmless test value (e.g. add a trailing slash difference you can visually confirm), wait a minute for GitHub's raw-content cache to catch up, force-close and relaunch the app, and confirm the change is picked up without a rebuild.
3. Turn off the device's network entirely before launching the app (first launch after install, so there's no cache yet) — confirm it falls back to `BuildConfig.BOOTSTRAP_BASE_URL` and doesn't crash.
4. Revert `server-config.json` back to `{"base_url": "https://theeasyride.eu"}` and push.

---

### Task 3: JS bridge skeleton + `ride-mode.php` hook (touches both repos)

**Files:**
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\RideBridge.kt`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\MainActivity.kt`
- Modify: `C:\Users\user\Desktop\easyride\ride-mode.php` (the **other** repo — this is the one edit to the existing web app called out in the spec)

**Interfaces:**
- Produces: `MainActivity.beginTrackingFlow(missionId: String, shiftId: String, csrfToken: String)` and `MainActivity.stopTrackingService()` — stub bodies for now (Task 4 replaces `beginTrackingFlow`'s body with the real permission flow; Task 5 replaces `stopTrackingService`'s body with the real service-stop call).

- [ ] **Step 1: Write `RideBridge.kt`**

```kotlin
package com.easyride.ridemode

import android.webkit.JavascriptInterface

class RideBridge(private val activity: MainActivity) {

    @JavascriptInterface
    fun startTracking(missionId: String, shiftId: String, csrfToken: String) {
        activity.runOnUiThread {
            activity.beginTrackingFlow(missionId, shiftId, csrfToken)
        }
    }

    @JavascriptInterface
    fun stopTracking() {
        activity.runOnUiThread {
            activity.stopTrackingService()
        }
    }
}
```

- [ ] **Step 2: Register the bridge and add stub handler methods in `MainActivity.kt`**

Add this line inside `resolveBaseUrlAndLoad()`'s `runOnUiThread` block, right after `setUpWebViewClient(url)`:
```kotlin
                webView.addJavascriptInterface(RideBridge(this@MainActivity), "AndroidRideBridge")
```

Add these two methods to the `MainActivity` class body (below `onPause`):
```kotlin
    fun beginTrackingFlow(missionId: String, shiftId: String, csrfToken: String) {
        android.widget.Toast.makeText(
            this,
            "Bridge: start tracking mission=$missionId shift=$shiftId",
            android.widget.Toast.LENGTH_LONG
        ).show()
    }

    fun stopTrackingService() {
        android.widget.Toast.makeText(this, "Bridge: stop tracking", android.widget.Toast.LENGTH_LONG).show()
    }
```

- [ ] **Step 3: Edit `ride-mode.php` in the `easyride` repo — add the bridge hooks**

In `C:\Users\user\Desktop\easyride\ride-mode.php`, inside `startTracking()`:
```javascript
    tracking = true;
    setPill(true, 'Ride Mode ενεργό');
    startBtn.disabled = true;
    stopBtn.disabled = false;
    clearGpsError();
    requestWakeLock();

    if (window.AndroidRideBridge) {
        window.AndroidRideBridge.startTracking(String(missionId), String(shiftId), csrfTokenValue);
    }

    watchId = navigator.geolocation.watchPosition(async position => {
```
(insert the `if (window.AndroidRideBridge)` block between the existing `requestWakeLock();` line and the existing `watchId = navigator.geolocation.watchPosition(...)` line — everything else in `startTracking()` is unchanged.)

And inside `stopTracking()`:
```javascript
function stopTracking() {
    tracking = false;
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    releaseWakeLock();
    setPill(false, 'Ανενεργό');
    startBtn.disabled = false;
    stopBtn.disabled = true;

    if (window.AndroidRideBridge) {
        window.AndroidRideBridge.stopTracking();
    }
}
```
(add the `if (window.AndroidRideBridge)` block at the end of the existing function body.)

- [ ] **Step 4: Commit both repos separately**

```bash
cd "C:/Users/user/Desktop/easyride-android"
git add -A
git commit -m "Add JavascriptInterface bridge skeleton (stub handlers)"
git push
```

```bash
cd "C:/Users/user/Desktop/easyride"
git add ride-mode.php
git commit -m "Hook Ride Mode start/stop into optional native Android bridge"
```
(Do not push/release the `easyride` repo change yet — bundle it with the release-workflow process per [[easyride-release-workflow]] whenever this session's other work is ready to ship, not mid-task.)

- [ ] **Step 5: Manual verification**

1. Rebuild and reinstall the Android app; log in, navigate to an active ride's Ride Mode screen, tap Start. Confirm the Toast "Bridge: start tracking mission=... shift=..." appears with real, correct IDs. Tap Stop; confirm the "Bridge: stop tracking" Toast appears.
2. Load the same `ride-mode.php` page in a normal desktop or mobile **browser** (not the app) — open devtools console, tap Start/Stop, confirm tracking behaves exactly as before with **no** `AndroidRideBridge is not defined` errors (the `if (window.AndroidRideBridge)` guard should make this a silent no-op).

---

### Task 4: Permission request flow

**Files:**
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\AndroidManifest.xml`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\MainActivity.kt`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\res\values\strings.xml`

**Interfaces:**
- Consumes: `beginTrackingFlow(missionId, shiftId, csrfToken)` stub from Task 3 (this task replaces its body).
- Produces: `MainActivity.launchTrackingService()` — called once every permission is resolved; Task 5 gives this a real body (currently a stub Toast).

- [ ] **Step 1: Add permissions to the manifest**

In `AndroidManifest.xml`, add these `<uses-permission>` lines right after the existing `INTERNET` one:
```xml
    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
    <uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
    <uses-permission android:name="android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS" />
```

- [ ] **Step 2: Add the new strings**

Replace `app/src/main/res/values/strings.xml` in full:
```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <string name="app_name">EasyRide Ride Mode</string>
    <string name="background_location_dialog_title">Στίγμα στο παρασκήνιο</string>
    <string name="background_location_dialog_message">Το EasyRide χρειάζεται πρόσβαση στην τοποθεσία «Πάντα» ώστε να συνεχίζει να στέλνει το στίγμα σου ακόμα κι όταν κλειδώσεις την οθόνη ή ελαχιστοποιήσεις την εφαρμογή.</string>
    <string name="background_location_dialog_positive">Συνέχεια</string>
    <string name="background_location_dialog_negative">Παράλειψη</string>
    <string name="battery_dialog_title">Αξιόπιστη λειτουργία στο παρασκήνιο</string>
    <string name="battery_dialog_message">Για να μη σταματά η μετάδοση στίγματος όταν κλειδώνεις την οθόνη, εξαίρεσε το EasyRide από την εξοικονόμηση μπαταρίας.</string>
    <string name="battery_dialog_positive">Άνοιγμα ρυθμίσεων</string>
    <string name="battery_dialog_negative">Παράλειψη</string>
    <string name="permission_denied_message">Χωρίς άδεια τοποθεσίας, το Ride Mode δεν μπορεί να στείλει στίγμα.</string>
</resources>
```

- [ ] **Step 3: Replace the full `MainActivity.kt` with the permission-flow version**

```kotlin
package com.easyride.ridemode

import android.Manifest
import android.annotation.SuppressLint
import android.app.AlertDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ProgressBar
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var loadingSpinner: ProgressBar
    private var resolvedBaseUrl: String = BuildConfig.BOOTSTRAP_BASE_URL

    private var pendingMissionId: String? = null
    private var pendingShiftId: String? = null
    private var pendingCsrfToken: String? = null

    private val fineLocationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) requestBackgroundLocationIfNeeded() else showPermissionDeniedMessage()
    }

    private val backgroundLocationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { requestNotificationPermissionIfNeeded() }

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { promptBatteryOptimizationExemption() }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.webView)
        loadingSpinner = findViewById(R.id.loadingSpinner)

        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, false)

        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true

        onBackPressedDispatcher.addCallback(this) {
            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                isEnabled = false
                onBackPressedDispatcher.onBackPressed()
            }
        }

        resolveBaseUrlAndLoad()
    }

    private fun resolveBaseUrlAndLoad() {
        val fetcher = HttpConfigFetcher(BuildConfig.SERVER_CONFIG_URL)
        val cache = PrefsConfigCache(applicationContext)

        Executors.newSingleThreadExecutor().execute {
            val url = ServerConfig.resolveBaseUrl(fetcher, cache, BuildConfig.BOOTSTRAP_BASE_URL)
            runOnUiThread {
                resolvedBaseUrl = url
                setUpWebViewClient(url)
                webView.addJavascriptInterface(RideBridge(this@MainActivity), "AndroidRideBridge")
                webView.loadUrl("$url/login.php")
                loadingSpinner.visibility = View.GONE
            }
        }
    }

    private fun setUpWebViewClient(baseUrl: String) {
        val baseHost = Uri.parse(baseUrl).host
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val url = request.url
                return if (url.host != null && url.host != baseHost) {
                    startActivity(Intent(Intent.ACTION_VIEW, url))
                    true
                } else {
                    false
                }
            }
        }
    }

    fun beginTrackingFlow(missionId: String, shiftId: String, csrfToken: String) {
        pendingMissionId = missionId
        pendingShiftId = shiftId
        pendingCsrfToken = csrfToken

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            == PackageManager.PERMISSION_GRANTED
        ) {
            requestBackgroundLocationIfNeeded()
        } else {
            fineLocationPermissionLauncher.launch(Manifest.permission.ACCESS_FINE_LOCATION)
        }
    }

    private fun requestBackgroundLocationIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            requestNotificationPermissionIfNeeded()
            return
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            == PackageManager.PERMISSION_GRANTED
        ) {
            requestNotificationPermissionIfNeeded()
            return
        }
        AlertDialog.Builder(this)
            .setTitle(R.string.background_location_dialog_title)
            .setMessage(R.string.background_location_dialog_message)
            .setPositiveButton(R.string.background_location_dialog_positive) { _, _ ->
                backgroundLocationPermissionLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            }
            .setNegativeButton(R.string.background_location_dialog_negative) { _, _ ->
                requestNotificationPermissionIfNeeded()
            }
            .show()
    }

    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED
        ) {
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        } else {
            promptBatteryOptimizationExemption()
        }
    }

    private fun promptBatteryOptimizationExemption() {
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        if (pm.isIgnoringBatteryOptimizations(packageName)) {
            launchTrackingService()
            return
        }
        AlertDialog.Builder(this)
            .setTitle(R.string.battery_dialog_title)
            .setMessage(R.string.battery_dialog_message)
            .setPositiveButton(R.string.battery_dialog_positive) { _, _ ->
                val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                    data = Uri.parse("package:$packageName")
                }
                startActivity(intent)
                launchTrackingService()
            }
            .setNegativeButton(R.string.battery_dialog_negative) { _, _ -> launchTrackingService() }
            .show()
    }

    private fun launchTrackingService() {
        Toast.makeText(
            this,
            "Would start service now for mission=$pendingMissionId shift=$pendingShiftId",
            Toast.LENGTH_LONG
        ).show()
    }

    fun stopTrackingService() {
        Toast.makeText(this, "Bridge: stop tracking", Toast.LENGTH_LONG).show()
    }

    private fun showPermissionDeniedMessage() {
        Toast.makeText(this, R.string.permission_denied_message, Toast.LENGTH_LONG).show()
    }

    override fun onPause() {
        super.onPause()
        CookieManager.getInstance().flush()
    }
}
```

- [ ] **Step 4: Commit and push**

```bash
git add -A
git commit -m "Add sequential location/notification/battery permission flow"
git push
```

- [ ] **Step 5: Manual verification**

1. Uninstall any previous build first (clean permission state), reinstall, log in, go to an active ride's Ride Mode, tap Start.
2. Confirm the OS fine-location permission dialog appears first; grant it.
3. Confirm the in-app explanation dialog appears next, then tapping "Συνέχεια" triggers the OS background-location flow (on Android 11+, this typically opens Settings directly — confirm it lands you on the right permission screen and "Allow all the time" can be selected).
4. Confirm the notification-permission dialog appears (Android 13+ devices only).
5. Confirm the battery-optimization exemption dialog appears, and tapping "Άνοιγμα ρυθμίσεων" opens the system's battery-exemption screen for this app.
6. After clicking through everything, confirm the final Toast ("Would start service now...") appears with the correct mission/shift IDs.
7. Repeat, but deny fine location at step 2 — confirm the "Χωρίς άδεια τοποθεσίας..." message shows and nothing else happens.

---

### Task 5: Foreground service skeleton

**Files:**
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\RideTrackingService.kt`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\AndroidManifest.xml`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\MainActivity.kt`
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\res\values\strings.xml`
- Create: `C:\Users\user\Desktop\easyride-android\app\src\main\res\drawable\ic_notification.xml`

**Interfaces:**
- Consumes: `launchTrackingService()` stub (Task 4) — this task replaces its body with a real `startForegroundService` call. `pendingMissionId`/`pendingShiftId`/`pendingCsrfToken` fields (Task 4).
- Produces: `RideTrackingService` with public constants `ACTION_STOP`, `EXTRA_MISSION_ID`, `EXTRA_SHIFT_ID`, `EXTRA_CSRF_TOKEN`, `EXTRA_BASE_URL` — Task 6 adds the GPS logic inside this same class.

- [ ] **Step 1: Add the notification icon**

`app/src/main/res/drawable/ic_notification.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<vector xmlns:android="http://schemas.android.com/apk/res/android"
    android:width="24dp"
    android:height="24dp"
    android:viewportWidth="24"
    android:viewportHeight="24"
    android:tint="#FFFFFF">
    <path
        android:fillColor="#FF000000"
        android:pathData="M12,2C8.13,2 5,5.13 5,9c0,5.25 7,13 7,13s7,-7.75 7,-13c0,-3.87 -3.13,-7 -7,-7zM12,11.5c-1.38,0 -2.5,-1.12 -2.5,-2.5s1.12,-2.5 2.5,-2.5 2.5,1.12 2.5,2.5 -1.12,2.5 -2.5,2.5z" />
</vector>
```
(A standard map-pin glyph — Android requires a notification icon to be a flat, single-color silhouette.)

- [ ] **Step 2: Add the two new strings**

Add these two lines inside `<resources>` in `strings.xml` (keep everything already there from Task 4):
```xml
    <string name="tracking_notification_title">EasyRide — Ride Mode ενεργό</string>
    <string name="tracking_notification_text">Το στίγμα σου μεταδίδεται. Πάτα Διακοπή για να σταματήσει.</string>
    <string name="tracking_notification_stop">Διακοπή</string>
    <string name="tracking_channel_name">Ride Mode Παρακολούθηση</string>
```

- [ ] **Step 3: Declare the service in the manifest**

Add this inside `<application>`, after the `<activity>` block, in `AndroidManifest.xml`:
```xml
        <service
            android:name=".RideTrackingService"
            android:foregroundServiceType="location"
            android:exported="false" />
```

- [ ] **Step 4: Write `RideTrackingService.kt` (skeleton — start/stop lifecycle and notification only, no GPS yet)**

```kotlin
package com.easyride.ridemode

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat

class RideTrackingService : Service() {

    companion object {
        const val CHANNEL_ID = "ride_tracking_channel"
        const val NOTIFICATION_ID = 1001
        const val ACTION_STOP = "com.easyride.ridemode.action.STOP_TRACKING"
        const val EXTRA_MISSION_ID = "mission_id"
        const val EXTRA_SHIFT_ID = "shift_id"
        const val EXTRA_CSRF_TOKEN = "csrf_token"
        const val EXTRA_BASE_URL = "base_url"
    }

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
            return START_NOT_STICKY
        }

        val notification = buildNotification()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }

        return START_STICKY
    }

    private fun buildNotification(): Notification {
        val stopIntent = Intent(this, RideTrackingService::class.java).apply { action = ACTION_STOP }
        val stopPendingIntent = PendingIntent.getService(
            this, 0, stopIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.tracking_notification_title))
            .setContentText(getString(R.string.tracking_notification_text))
            .setSmallIcon(R.drawable.ic_notification)
            .setOngoing(true)
            .addAction(0, getString(R.string.tracking_notification_stop), stopPendingIntent)
            .build()
    }

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.tracking_channel_name),
            NotificationManager.IMPORTANCE_LOW
        )
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(channel)
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
```

- [ ] **Step 5: Wire it up — replace `launchTrackingService()` and `stopTrackingService()` in `MainActivity.kt`**

```kotlin
    private fun launchTrackingService() {
        val intent = Intent(this, RideTrackingService::class.java).apply {
            putExtra(RideTrackingService.EXTRA_MISSION_ID, pendingMissionId)
            putExtra(RideTrackingService.EXTRA_SHIFT_ID, pendingShiftId)
            putExtra(RideTrackingService.EXTRA_CSRF_TOKEN, pendingCsrfToken)
            putExtra(RideTrackingService.EXTRA_BASE_URL, resolvedBaseUrl)
        }
        ContextCompat.startForegroundService(this, intent)
    }

    fun stopTrackingService() {
        val intent = Intent(this, RideTrackingService::class.java).apply {
            action = RideTrackingService.ACTION_STOP
        }
        startService(intent)
    }
```
Add the import `androidx.core.content.ContextCompat` if not already present (it is, from Task 4).

- [ ] **Step 6: Commit and push**

```bash
git add -A
git commit -m "Add RideTrackingService skeleton: foreground notification, start/stop lifecycle"
git push
```

- [ ] **Step 7: Manual verification**

1. Rebuild, reinstall, log in, go to Ride Mode, tap Start, click through all permission prompts.
2. Confirm the persistent "EasyRide — Ride Mode ενεργό" notification appears, with a "Διακοπή" action.
3. Minimize the app (press Home) — confirm the notification stays.
4. Lock the screen for ~30 seconds, unlock — confirm the notification is still there (service wasn't killed).
5. Tap "Διακοπή" on the notification — confirm the notification disappears.
6. Repeat, but this time tap Stop from inside the app's Ride Mode UI (via the bridge) instead of the notification — confirm the same result.

---

### Task 6: GPS acquisition, ping POSTs, and auto-stop on repeated failure

**Files:**
- Modify: `C:\Users\user\Desktop\easyride-android\app\src\main\java\com\easyride\ridemode\RideTrackingService.kt`

**Interfaces:**
- Consumes: `EXTRA_MISSION_ID`/`EXTRA_SHIFT_ID`/`EXTRA_CSRF_TOKEN`/`EXTRA_BASE_URL` intent extras (Task 5).
- Produces: nothing new consumed by later tasks — this is the last piece of the core tracking behavior.

- [ ] **Step 1: Replace the full `RideTrackingService.kt` with the GPS + ping version**

```kotlin
package com.easyride.ridemode

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.webkit.CookieManager
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import okhttp3.Call
import okhttp3.Callback
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

class RideTrackingService : Service() {

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private var locationCallback: LocationCallback? = null
    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(10, TimeUnit.SECONDS)
        .build()

    private var missionId: String = ""
    private var shiftId: String = ""
    private var csrfToken: String = ""
    private var baseUrl: String = ""
    private var consecutiveFailures = 0

    companion object {
        const val CHANNEL_ID = "ride_tracking_channel"
        const val NOTIFICATION_ID = 1001
        const val ACTION_STOP = "com.easyride.ridemode.action.STOP_TRACKING"
        const val EXTRA_MISSION_ID = "mission_id"
        const val EXTRA_SHIFT_ID = "shift_id"
        const val EXTRA_CSRF_TOKEN = "csrf_token"
        const val EXTRA_BASE_URL = "base_url"
        private const val PING_INTERVAL_MS = 10_000L
        private const val MAX_CONSECUTIVE_FAILURES = 3
    }

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopTracking()
            return START_NOT_STICKY
        }

        missionId = intent?.getStringExtra(EXTRA_MISSION_ID) ?: ""
        shiftId = intent?.getStringExtra(EXTRA_SHIFT_ID) ?: ""
        csrfToken = intent?.getStringExtra(EXTRA_CSRF_TOKEN) ?: ""
        baseUrl = intent?.getStringExtra(EXTRA_BASE_URL) ?: ""

        if (missionId.isEmpty() || shiftId.isEmpty() || csrfToken.isEmpty() || baseUrl.isEmpty()) {
            stopSelf()
            return START_NOT_STICKY
        }

        val notification = buildNotification()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }

        startLocationUpdates()
        return START_STICKY
    }

    private fun startLocationUpdates() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED
        ) {
            stopSelf()
            return
        }

        val locationRequest = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, PING_INTERVAL_MS)
            .setMinUpdateIntervalMillis(PING_INTERVAL_MS)
            .build()

        val callback = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                result.lastLocation?.let { sendPing(it) }
            }
        }
        locationCallback = callback

        fusedLocationClient.requestLocationUpdates(locationRequest, callback, mainLooper)
    }

    private fun sendPing(location: Location) {
        val cookie = CookieManager.getInstance().getCookie(baseUrl) ?: ""

        val formBody = FormBody.Builder()
            .add("csrf_token", csrfToken)
            .add("mission_id", missionId)
            .add("shift_id", shiftId)
            .add("lat", location.latitude.toString())
            .add("lng", location.longitude.toString())
            .add("accuracy", if (location.hasAccuracy()) location.accuracy.toString() else "")
            .add("speed", if (location.hasSpeed()) location.speed.toString() else "")
            .add("heading", if (location.hasBearing()) location.bearing.toString() else "")
            .add("battery_level", batteryLevelPercent()?.toString() ?: "")
            .add("status", "")
            .build()

        val request = Request.Builder()
            .url("$baseUrl/api-ride-ping.php")
            .header("Cookie", cookie)
            .post(formBody)
            .build()

        httpClient.newCall(request).enqueue(object : Callback {
            override fun onFailure(call: Call, e: IOException) {
                registerFailure()
            }

            override fun onResponse(call: Call, response: Response) {
                response.use {
                    val ok = try {
                        JSONObject(it.body?.string() ?: "").optBoolean("ok", false)
                    } catch (e: Exception) {
                        false
                    }
                    if (ok) consecutiveFailures = 0 else registerFailure()
                }
            }
        })
    }

    private fun registerFailure() {
        consecutiveFailures++
        if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
            stopTracking()
        }
    }

    private fun batteryLevelPercent(): Int? {
        val bm = getSystemService(BatteryManager::class.java) ?: return null
        val level = bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        return if (level in 0..100) level else null
    }

    private fun stopTracking() {
        locationCallback?.let { fusedLocationClient.removeLocationUpdates(it) }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun buildNotification(): Notification {
        val stopIntent = Intent(this, RideTrackingService::class.java).apply { action = ACTION_STOP }
        val stopPendingIntent = PendingIntent.getService(
            this, 0, stopIntent,
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.tracking_notification_title))
            .setContentText(getString(R.string.tracking_notification_text))
            .setSmallIcon(R.drawable.ic_notification)
            .setOngoing(true)
            .addAction(0, getString(R.string.tracking_notification_stop), stopPendingIntent)
            .build()
    }

    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.tracking_channel_name),
            NotificationManager.IMPORTANCE_LOW
        )
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(channel)
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
```

Note what changed from Task 5's skeleton: `ACTION_STOP` now calls the new `stopTracking()` (which also removes location updates, not just the notification); `onStartCommand` now reads the four intent extras, bails out via `stopSelf()` if any are missing, and calls `startLocationUpdates()`; `sendPing()`, `registerFailure()`, and `batteryLevelPercent()` are new.

- [ ] **Step 2: Commit and push**

```bash
git add -A
git commit -m "Wire GPS acquisition and ping POSTs into RideTrackingService"
git push
```

- [ ] **Step 3: Manual verification — this is the actual acceptance test for the whole feature**

1. As a rider with a real approved participation on an active mission, log in through the app, start tracking on that shift, click through all permissions.
2. As a second observer (a different browser session logged in as the mission's responsible person, viewing `ride-mode.php` in Controller mode, or querying the `member_pings` table directly), confirm pings are landing roughly every 10 seconds with sane `lat`/`lng`/`accuracy` values.
3. Lock the phone's screen. Wait at least 3 minutes. Confirm pings continue landing throughout — this is the entire point of the app.
4. Switch to a different app (e.g. open Google Maps) instead of locking the screen. Confirm pings still continue.
5. Tap "Διακοπή" on the notification. Confirm pings stop and the notification clears.
6. Force-stop the app from Android's app-info screen while tracking is active. Confirm the notification and service are torn down by the OS, and the mission's live view correctly shows the rider going stale after ~2 minutes (existing v3.87.0 behavior).
7. To test the auto-stop-on-failure path: temporarily change `EXTRA_BASE_URL`'s value at the source (e.g. briefly point `resolvedBaseUrl` at an unreachable host, rebuild, start tracking) and confirm the service stops itself (notification clears) after 3 consecutive failed ping attempts (~30 seconds) rather than running forever against a dead endpoint. Revert the change afterward.

---

### Task 7: Release signing and GitHub Releases distribution

**Files:**
- Create: `C:\Users\user\Desktop\easyride-android\keystore.properties` (gitignored — never committed)
- Modify: `C:\Users\user\Desktop\easyride-android\app\build.gradle.kts`

**Interfaces:**
- None — this is the final packaging/distribution task, nothing downstream depends on it.

- [ ] **Step 1: Generate a release keystore, stored outside the repo**

```bash
mkdir -p "C:/Users/user/keys"
keytool -genkeypair -v \
  -keystore "C:/Users/user/keys/easyride-release.jks" \
  -alias easyride \
  -keyalg RSA -keysize 2048 -validity 10000
```
You'll be prompted for a keystore password, your name/org details, and a key password. **Write these passwords down somewhere durable (a password manager) — losing them means every future release needs a brand-new keystore, which breaks updates for anyone who already installed the app**, since Android refuses to install an "update" signed with a different key than the one already on the device.

- [ ] **Step 2: Write `keystore.properties` (repo root, already gitignored from Task 1)**

```properties
storeFile=C:/Users/user/keys/easyride-release.jks
storePassword=REPLACE_WITH_YOUR_ACTUAL_KEYSTORE_PASSWORD
keyAlias=easyride
keyPassword=REPLACE_WITH_YOUR_ACTUAL_KEY_PASSWORD
```
Fill in the real passwords from Step 1 — this file is `.gitignore`'d and must never be committed.

- [ ] **Step 3: Wire signing into `app/build.gradle.kts`**

Add near the top of the file, before the `android {` block:
```kotlin
import java.util.Properties
import java.io.FileInputStream

val keystorePropertiesFile = rootProject.file("keystore.properties")
val keystoreProperties = Properties()
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}
```

Inside the `android { ... }` block, add a `signingConfigs` block (before `buildTypes`) and update `buildTypes`:
```kotlin
    signingConfigs {
        create("release") {
            if (keystorePropertiesFile.exists()) {
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (keystorePropertiesFile.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }
```
(This keeps `assembleRelease` working — unsigned — on any machine without `keystore.properties`, e.g. if someone else ever clones the repo, rather than hard-failing Gradle sync.)

- [ ] **Step 4: Build the signed release APK**

```bash
cd "C:/Users/user/Desktop/easyride-android"
./gradlew assembleRelease
```
Expected output: `app/build/outputs/apk/release/app-release.apk`.

- [ ] **Step 5: Commit the Gradle signing wiring (not the keystore or properties file — confirm `git status` shows neither)**

```bash
git status
git add app/build.gradle.kts
git commit -m "Wire release signing config (reads gitignored keystore.properties)"
git push
```

- [ ] **Step 6: Create the GitHub release with the signed APK attached**

```bash
gh release create v1.0.0 \
  "app/build/outputs/apk/release/app-release.apk" \
  --title "EasyRide Ride Mode v1.0.0" \
  --notes "Πρώτη έκδοση: Ride Mode με GPS που συνεχίζει να στέλνει στίγμα ακόμα κι όταν κλειδώσεις την οθόνη ή ελαχιστοποιήσεις την εφαρμογή."
```

- [ ] **Step 7: Manual verification**

1. On a device that has never had this app installed (or after uninstalling it), download the APK directly from the GitHub release page and sideload it (enabling "install from unknown sources" if prompted).
2. Confirm it installs and runs identically to the debug builds tested in Tasks 1–6.
3. Confirm `gh release list` in the `easyride-android` repo shows `v1.0.0`.

