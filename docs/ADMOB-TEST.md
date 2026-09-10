# AdMob 테스트 가이드

이 저장소는 PHP 웹 프로젝트라서 **AdMob 광고를 `ad-preview.php` 안에 HTML로 삽입할 수 없다.** AdMob은 네이티브 앱의 Google Mobile Ads SDK에서 요청한다.

실제 앱 방어까지 검증하려면 Android/iOS/Flutter/Unity 프로젝트 쪽에 별도의 AdMob policy adapter가 필요하다. 웹의 `ad_defense_ads_allowed()`와 같은 서버 정책을 앱 API로 전달하고, 앱은 HIGH/CRITICAL일 때 Mobile Ads SDK의 광고 request/show 경로를 실행하지 않는 구조가 권장된다.

## 반드시 Test Ads 사용

개발/검증 중에는 운영 AdMob 광고를 반복 요청하거나 클릭하지 않는다. Google의 demo ad unit 또는 Test Device 기능을 사용한다.

### Android 공식 demo ad unit IDs

- App Open: `ca-app-pub-3940256099942544/9257395921`
- Anchored/Inline Adaptive Banner: `ca-app-pub-3940256099942544/9214589741`
- Fixed Size Banner: `ca-app-pub-3940256099942544/6300978111`
- Interstitial: `ca-app-pub-3940256099942544/1033173712`
- Rewarded: `ca-app-pub-3940256099942544/5224354917`
- Rewarded Interstitial: `ca-app-pub-3940256099942544/5354046379`
- Native: `ca-app-pub-3940256099942544/2247696110`
- Native Video: `ca-app-pub-3940256099942544/1044960115`

### iOS 공식 demo ad unit IDs

- App Open: `ca-app-pub-3940256099942544/5575463023`
- Anchored/Inline Adaptive Banner: `ca-app-pub-3940256099942544/2435281174`
- Fixed Size Banner: `ca-app-pub-3940256099942544/2934735716`
- Interstitial: `ca-app-pub-3940256099942544/4411468910`
- Rewarded: `ca-app-pub-3940256099942544/1712485313`
- Rewarded Interstitial: `ca-app-pub-3940256099942544/6978759866`
- Native: `ca-app-pub-3940256099942544/3986624511`
- Native Video: `ca-app-pub-3940256099942544/2521693316`

## 앱 테스트 Acceptance Criteria

1. LOW/MEDIUM: Test Ad request 허용.
2. HIGH/CRITICAL: Banner/App Open/Interstitial/Rewarded/Native 등 **모든 AdMob request/show 경로 금지**.
3. 포맷명 whitelist에 의존하지 않고 공통 `ads_allowed` policy를 앱 광고 서비스 상단에서 확인.
4. 운영 unit ID 대신 Google demo IDs 또는 Test Device만 사용.
5. 광고 클릭 자동화 금지.

## 현재 프로젝트에서 할 수 없는 것

Android/iOS 앱 소스가 이 압축본에 없으므로 SDK 초기화, Activity/ViewController, WebView bridge까지 실제 연결하는 작업은 현재 저장소만으로는 검증할 수 없다.
