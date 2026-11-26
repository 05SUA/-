# LOREVERSE 개발 및 배포

[prd.md](prd.md)는 페이지 구성, 디자인, 기술 스택과 개발 단계의 기준이다. [AGENTS.md](AGENTS.md)는 개발 지침이다. 프로젝트는 PHP, MySQL, 서버 측 Gemini와 브라우저의 동일 호스트 요청을 사용한다.

| 경로 | 역할 |
|---|---|
| `public/` | 공개 웹 루트. PHP 진입점, API와 정적 에셋을 포함한다. |
| `private/` | 웹 루트 외부의 PHP 공통 코드, 비공개 설정, AI 서비스 |
| `database/schema.sql` | PRD §7의 11개 테이블. 데이터베이스를 먼저 만든 뒤 빈 DB에서 가져온다. |
| `tests/stage01.php` | 로컬 CLI 및 임시 브라우저 진단 스크립트 |

## 기존 InfinityFree 사이트 업데이트

1. 저장소의 `submission/LOREVERSE-Existing-Site-Update.zip`을 다운로드한다.
2. InfinityFree File Manager에서 **loreverse.42web.io → htdocs → loreverse** 폴더를 연다. 현재 경로가 `/loreverse.42web.io/htdocs/loreverse`이고 그 안에 `public`, `private`가 보이는 상태에서 **Upload & Unzip**으로 올린다. 덮어쓰기 안내가 나오면 승인한다.
3. 이 폴더의 `public/workspace.php`와 `private/views/conversation.php`가 보이는지 확인한다. 일반 Chrome이나 Edge에서 AI 대화 주소에 접속한다. 로그인한 뒤 캐릭터 하나를 골라 짧은 메시지를 보내 실제 답변을 확인한다. [운영 AI 대화](https://loreverse.42web.io/loreverse/public/workspace.php?tab=conversation)

업데이트 압축에는 운영 설정 파일과 사용자 업로드가 들어 있지 않다. 기존 DB와 영상·Gemini 설정으로 실행하며, 003·004 SQL을 적용한 현재 운영 DB에는 추가 SQL이 필요 없다. 처음 설치하는 경우에는 아래의 시연용 전체 패키지와 DB 설치 절차를 따른다.

전체 페이지를 이미 설치한 사이트는 `submission/LOREVERSE-Conversation-Update.zip`으로 대화 페이지의 진입 경로만 적용할 수 있다. 압축 해제 위치는 동일한 `/loreverse.42web.io/htdocs/loreverse`다. 운영 브라우저에서 `chat.php` 문서 요청이 InfinityFree 403 페이지로 리다이렉트되는 것을 확인했다. 대화 메뉴는 작업 공간 진입점에서 같은 인증·대화 화면과 같은 PHP API를 사용한다.

캐릭터 초안 생성 후 요청이 `ai_usage_logs`에서 `pending`에 멈추는 경우에는 `submission/LOREVERSE-Creator-Recovery.zip`을 같은 폴더에 적용한다. 로컬에서 Gemini 요청을 기다리는 동안 MariaDB 연결을 닫아 같은 증상을 재현했다. 수정본은 MySQL 연결 단절 오류가 확인된 경우 새 DB 연결로 해당 요청의 기록 갱신만 한 번 재시도하고, Gemini 오류가 있으면 원래 오류를 표시한다. 운영 사이트에서는 실제 응답과 최근 `character_generate` 행의 최종 상태를 확인한다.

### AI 프로필 이미지와 스토리

1. 기존 운영 사이트에 `submission/LOREVERSE-AI-Portrait-Update.zip`을 `/loreverse.42web.io/htdocs/loreverse`에서 **Upload & Unzip**으로 적용한다.
2. 기존 Gemini 키가 저장된 비공개 `config.local.php`의 배열에 이미지 모델 설정을 추가한다. 시연용 전체 패키지로 설치한 현재 사이트는 도메인 폴더의 `htdocs/loreverse-stage01/private/config.local.php`를 읽는다.

   ```php
   'GEMINI_IMAGE_MODEL' => 'gemini-3.1-flash-image',
   ```

   기존 설치에서 설정을 추가했는데도 이미지 모델 설정 안내가 표시되면 `htdocs/loreverse/private/config.local.php`의 `$allowed` 목록을 확인한다. 이 파일이 `loreverse-stage01` 설정을 읽는 기존 연결 파일이라면, 목록의 `'GEMINI_MODEL',` 뒤에 `'GEMINI_IMAGE_MODEL',`을 추가한다. 최신 전체 설치 ZIP의 연결 파일은 예제 설정의 키를 기준으로 운영 설정을 가져온다.

   여전히 같은 안내가 표시되는 기존 사이트는 `submission/LOREVERSE-Portrait-Model-Enable.zip`을 `/loreverse.42web.io/htdocs/loreverse`에서 **Upload & Unzip**으로 적용할 수 있다. 이 업데이트가 설치하는 `private/config.local.portrait-model.txt`에는 이미지 모델 ID 한 줄이 들어 있다. 이미지 생성기는 기본 설정에서 모델이 비어 있을 때 이 파일을 읽는다.

   운영 Google AI 프로젝트에서 활성화한 이미지 생성 모델 ID를 사용한다. 모델의 제공 조건과 할당량은 해당 프로젝트에서 확인한다. [Google Gemini 이미지 생성 예시](https://github.com/google-gemini/cookbook/blob/main/quickstarts/Get_Started_Nano_Banana.ipynb)

3. 저장한 캐릭터의 **프로필 → AI 이미지 만들기**에서 결과를 미리 보고 **프로필 이미지로 저장**한다. 캐릭터의 기존 아바타를 변경하는 작업은 미리보기에서 저장을 선택했을 때 수행한다.
4. 세계관 메뉴의 **스토리**에서 해당 세계관의 캐릭터를 고르고 **스토리 만들기 → Gemini로 첫 장면 만들기**를 누른다. 첫 장면이 저장되면 선택지나 직접 행동으로 다음 전개를 생성한다.

사용자가 기존 운영 사이트의 Gemini 대화와 캐릭터 텍스트 초안 생성을 확인했다. 로컬에서 스토리의 Gemini 응답을 기다리는 동안 끊긴 DB 연결을 장면 저장 전에 복구하는 흐름을 검증했다. AI 프로필 이미지와 스토리 장면 생성은 운영 브라우저에서 확인한다.

## 05–08단계 창작 스튜디오

| 단계 | 페이지 | 지원하는 작업 |
|---|---|---|
| 05 | `characters.php`, `character-create.php`, `character.php` | 세계관 소속·독립 캐릭터 생성, 프로필·이미지 편집, 목록 필터, 복제 및 삭제 |
| 06 | `relationships.php` | 방향성 관계의 그래프와 목록, 친밀도·신뢰도 편집, Gemini 관계 서사 제안 |
| 07 | `chat.php` | 캐릭터와 대화 모드 선택, 실제 Gemini 응답, 대화 기록과 세션 재개 |
| 08 | `story.php`, `timeline.php`, `mypage.php`, `workspace.php?tab=favorites` | 등장인물을 선택해 스토리 시작, 직접 쓰거나 Gemini로 장면 진행, 승인한 사건·관계를 공식 설정에 반영, 타임라인·즐겨찾기·계정 관리 |

HOME, EXPLORE, 로그인·가입, 작업 공간, 세계관 빌더·개요와 위 화면을 합해 PRD에 정한 14개 화면 유형(로그인과 가입을 별도로 세면 PHP 진입점 15개)을 제공한다. 스토리는 세계관에서 캐릭터를 만든 후 시작할 수 있다.

로컬 HTTPS·MariaDB에서 페이지 탐색, 창작 데이터의 저장·재접근, 다른 계정의 접근 차단, 스토리의 공식 사건 반영을 확인했다. 생성 API의 응답 검증과 장애 처리는 격리 테스트에서 확인했다. 기존 운영 앱의 장르 추천·영상·세계관 저장은 아래 검증 기록을 참조한다. 확장판의 운영 배포와 Gemini 텍스트 생성 성공 확인은 남아 있다.

## 로컬 (Windows XAMPP)

1. XAMPP에서 MySQL을 시작한다. PHP 8.x에서 `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `session`을 사용할 수 있어야 한다.
2. phpMyAdmin에서 `loreverse_db` 데이터베이스를 `utf8mb4_unicode_ci`로 만든다. DB를 선택하고 **가져오기**에서 `database/schema.sql`을 실행한다. 로컬 앱용 계정을 별도로 만들고 이 DB에만 SELECT, INSERT, UPDATE, DELETE 권한을 부여한다. 스키마를 가져오는 데에는 테이블 생성 권한이 있는 로컬 계정을 사용한다.
3. PowerShell에서 `LOREVERSE` 폴더를 연다. `Copy-Item private/config.example.php private/config.local.php`로 설정 파일을 만들고 DB 접속 값을 채운 뒤 아래 명령을 실행한다.

   ```powershell
   & 'C:\xampp\php\php.exe' tests/stage01.php runtime
   & 'C:\xampp\php\php.exe' tests/stage01.php database
   ```

   PHP가 PATH에 있으면 `& 'C:\xampp\php\php.exe'` 대신 `php`를 쓸 수 있다. 로컬 Apache를 사용할 경우 `public/`을 DocumentRoot로 지정한다. XAMPP 내장 MySQL이 MariaDB로 표시되는 환경에서도 같은 스키마를 사용한다.

## 비공개 설정과 Gemini

| 설정 | 입력값 |
|---|---|
| `APP_ENV` / `APP_BASE_URL` | 로컬은 `local`과 로컬 주소, 운영은 `production`과 실제 HTTPS 주소 |
| `DB_HOST`, `DB_PORT`, `DB_NAME` | 로컬 기본 호스트/포트는 `127.0.0.1:3306`. 운영은 호스팅 제어판 값 |
| `DB_USER`, `DB_PASSWORD` | 해당 환경의 DB 계정 정보 |
| `GEMINI_API_KEY` | Google AI Studio에서 생성한 Gemini Developer API 키 |
| `GEMINI_MODEL` | 해당 계정에서 사용 가능하고 구조화 출력을 지원하는 모델 ID. `models/` 접두사 없이 입력 |
| `GEMINI_FALLBACK_MODEL` | Google이 503 `UNAVAILABLE` 또는 404 `NOT_FOUND`를 확인했을 때 시도할 구조화 출력 모델. 예제 기본값은 `gemini-3.5-flash-lite`, 비공개 설정에 빈 문자열을 지정하면 기본 모델만 호출한다. 두 호출에는 동일한 25초 상한을 적용한다. |
| `GEMINI_IMAGE_MODEL` | 캐릭터 이미지 생성에 사용하는 별도 Gemini 모델. 운영 프로젝트에서 지원하는 이미지 모델을 지정한다. 기본값은 빈 문자열이며, 모델을 설정하면 이미지 생성 요청을 처리한다. |
| `HERO_VIDEO_URL`, `AI_DAILY_LIMIT` | 생성형 영상의 HTTPS 주소를 설정한다. 일일 AI 제한의 초기값은 30이다. |
| `STAGE01_DIAGNOSTIC_TOKEN` | 브라우저 진단에만 사용하는 임시 64자리 소문자 16진 토큰. CLI 진단에는 필요 없다. |

Google AI Studio의 **Get API key / API keys**에서 사용할 Google 프로젝트를 선택하고 키를 만든다. [Google AI Studio API 키 관리](https://aistudio.google.com/app/apikey)

생성한 키와 모델을 `private/config.local.php`에 입력하고 계정의 무료 할당량과 비용 조건을 확인한 뒤 `php tests/stage01.php gemini`를 실행한다. 이 명령은 작은 한국어 JSON 응답을 한 번 실제 요청하고 형식이 맞아야 성공한다. Codex Cloud에서 검증하는 경우 HTTPS 대상 `generativelanguage.googleapis.com` 허용이 필요하다. 환경에서 안전하게 주입된 `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_IMAGE_MODEL` 값이 있으면 PHP가 파일 값보다 우선 사용한다. [구조화 출력 지원 모델과 REST 예시](https://ai.google.dev/gemini-api/docs/structured-output)

실제 키와 비밀번호는 로컬 설정 파일이나 승인된 비밀값 관리 기능에 입력한다. Git, 채팅, JavaScript, URL에는 넣지 않는다. `GEMINI_CONFIG_INVALID`는 설정 누락 또는 ID 형식 오류이고, `GEMINI_NETWORK_ERROR`, `GEMINI_AUTH_ERROR`, `GEMINI_RATE_LIMIT`, `GEMINI_MODEL_UNAVAILABLE`는 연결, 인증, 할당량, 모델 접근을 각각 확인해야 한다.

## 브라우저와 InfinityFree 01단계 검증

로컬에서는 `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`로 임시 토큰을 만들어 비공개 설정에 기록한다. `tests/stage01.php`를 `public/_stage01.php`로 복사하고 `php -S 127.0.0.1:8765 -t public`을 시작한 뒤 브라우저에서 `http://127.0.0.1:8765/_stage01.php`를 연다. 로컬 PHP 내장 서버는 `.htaccess`를 처리하지 않으므로 이 경우 `public/` 밖에 있는 비공개 폴더 배치가 필요하다.

InfinityFree의 계정 및 도메인이 준비되면 다음 순서로 테스트한다.

1. 도메인의 HTTPS 인증서를 활성화하고 브라우저에서 인증서 오류 없이 접속되는지 확인한다. 제어판에서 **MySQL Databases**를 열어 DB를 만들고, 표시된 DB 호스트·DB명·사용자명을 그대로 기록한다. 비밀번호는 제어판의 해당 DB 접속 정보에 따른다. 제공 phpMyAdmin에서 그 DB를 선택하고 `database/schema.sql`을 가져온다.
2. 파일 매니저 또는 FTP로 `public/`의 내용과 숨김 파일인 `.htaccess`를 `htdocs/`에 둔다. `private/`는 가능하면 `htdocs/`와 나란히 둔다. 루트 밖에 배치할 수 없다면 `htdocs/private/`로 배치하고, 비밀값을 올리기 전에 `private/.htaccess`의 직접 접근 차단이 403/404로 작동하는지 확인한다. 차단이 안 되면 비밀을 올리기 전에 호스팅 배치를 수정한다. SQL·PRD·README·테스트 폴더는 배포 폴더에 복사하지 않는다.
3. 운영 `private/config.local.php`에 `APP_ENV => 'production'`, 실제 HTTPS `APP_BASE_URL`, 운영 DB 정보, Gemini 키와 모델, 로컬과 별도로 생성한 임시 진단 토큰을 넣는다. 운영 PHP의 오류 출력은 끈다.
4. `tests/stage01.php`만 임시로 `htdocs/_stage01.php`에 복사한다. 같은 HTTPS 도메인의 `/_stage01.php`를 브라우저에서 열고 토큰을 입력한 다음 **동일 호스트 POST → PHP 환경 → PDO와 DB 스키마 → Gemini 실제 JSON 요청**을 각각 실행한다. 브라우저에서는 `same_origin_post`, DB에서는 `schema_tables_verified: 11`, AI에서는 `gemini_json_validated`를 확인한다. PHP/DB 버전, 확장, 실행·업로드 한도 및 점검 날짜도 기록한다. Gemini 쿼터와 외부 HTTPS 443 응답은 해당 운영 호스트에서 확인한다.
5. 브라우저로 `/private/config.example.php`, `/private/config.local.php`, `/database/schema.sql`, `/uploads/`에 직접 접근해 403/404인지 확인한다. 점검 후 `_stage01.php`를 제거하고 진단 토큰을 비운다. 제거한 URL도 403/404인지 확인한다.

운영의 브라우저 보호와 동일 호스트 동작은 실제 브라우저에서 확인한다. 운영 DB도 해당 호스트의 PHP 및 phpMyAdmin으로 검증한다. [InfinityFree 브라우저 API 정책](https://forum.infinityfree.com/t/why-isnt-api-access-working-on-my-website/115198/1) · [외부 HTTPS 연결 안내](https://forum.infinityfree.com/t/can-i-connect-to-other-servers-from-my-website-sockets-curl-and-apis/49348)

## 클라우드 로컬 검증 기록

- PHP 8.4.28에서 문법 검사와 필수 확장 확인 통과.
- MariaDB 11.8.9에서 스키마 11개 테이블 설치, PDO 연결, InnoDB·utf8mb4·UTC, 외래 키 18개, 중복 이메일 제약을 확인했다. 한글·이모지 저장·조회 점검은 트랜잭션 롤백으로 정리했다.
- 로컬 Apache와 Chromium에서 같은 호스트의 Fetch POST, 브라우저 PHP/PDO 점검, 토큰·CSRF·출처 확인, 비공개 설정·SQL·업로드의 직접 접근 차단을 확인했다.
- 초기 클라우드 점검에서 Gemini의 설정 누락 오류 처리와 Gemini HTTPS 목적지 연결의 프록시 403을 확인했다. 실제 Gemini 요청은 아래 InfinityFree 운영 진단에서 검증했다.

## InfinityFree 01단계 운영 검증 (2026-11-26, KST)

사용자가 `https://loreverse.42web.io`의 브라우저 진단 결과를 확인해 전달했다.

- HTTPS 접속 및 운영 PHP를 통한 PDO 연결 확인. `schema_tables_verified: 11`로 InnoDB·utf8mb4 테이블 11개를 확인했다.
- `same_origin_post: true`로 운영 브라우저의 동일 호스트 POST를 확인했다.
- `gemini_json_validated: true`로 `gemini-flash-latest`를 사용한 실제 Gemini JSON 요청과 응답 형식 검증을 확인했다.
- 사용자가 `/loreverse-stage01/private/_access-check.txt`의 직접 HTTP 접근 차단(403/404)을 확인했다.
- 별도로 실행한 PHP 환경 진단은 `DIAGNOSTIC_AUTH_REQUIRED`에서 중단되어 PHP 버전·실행·업로드 한도는 미기록으로 남겼다. 사용자가 운영 임시 진단 파일 제거 및 진단 토큰 정리를 완료했다고 보고했다.

## 02단계 공통 UI

`public/assets/css/`의 `tokens.css`, `common.css`, `layout.css`를 순서대로 로드한다. `public/includes/header.php`는 공개·스튜디오 헤더를, `public/includes/sidebar.php`는 메뉴 목록을 렌더링한다. 링크는 앱 코드에서 지정한 경로를 전달한다. `public/assets/js/common.js`가 공개 CREATE 메뉴와 작은 화면의 네이티브 다이얼로그 동작을 제공한다.

프로젝트 폴더에서 아래 명령을 실행하고 브라우저로 `http://127.0.0.1:8765/_stage02`를 연다. 이 경로는 로컬 테스트 라우터에만 등록된다.

```sh
php -S 127.0.0.1:8765 -t public tests/stage02-router.php
```

확인 범위는 버튼·입력, 공개 헤더·스튜디오 상단바와 사이드바, 모바일 상세 패널, 로딩·빈 화면·오류·성공 상태다. HOME·EXPLORE·인증은 다음 단계에서 이 요소를 사용한다.

클라우드 Chromium에서 1440·1024·768·390·360px의 가로 넘침, 세 글꼴 로드, 키보드 탐색과 포커스 복귀, 메뉴 선택, 입력 오류 및 상태 전환, 모션 감소 설정을 확인했다. 기본 버튼의 글자 대비는 5.28:1이다. PHP와 JavaScript 문법 검사도 통과했다.

글꼴과 아이콘은 로컬 자산으로 배포한다. Space Grotesk와 IBM Plex Mono는 [Google Fonts 저장소](https://github.com/google/fonts)의 OFL 파일, Pretendard는 [공식 저장소](https://github.com/orioncactus/pretendard)의 변수 글꼴과 라이선스, 라인 아이콘은 [Lucide 저장소](https://github.com/lucide-icons/lucide)의 SVG와 라이선스를 함께 포함한다. 포털 마크는 레이아웃 점검용 CSS 심볼이며 최종 브랜드 에셋은 09단계에서 적용한다.

## 03단계 HOME·EXPLORE·인증

1. 로컬 DB에 `database/schema.sql`의 기본 테이블을 설치한다. 이미 01단계를 완료한 DB에는 `database/migrations/003_auth_rate_limits.sql`만 적용한다. 새로 설치할 때도 기본 스키마 뒤에 이 SQL을 적용한다. 이 파일은 가입·로그인 요청 한도를 기록할 테이블 하나를 추가하며 재실행할 수 있다.
2. `private/config.local.php`의 DB 연결값과 `APP_BASE_URL`을 현재 실행 주소에 맞춘다. XAMPP의 서브폴더를 사용한다면 주소에 해당 경로도 포함한다.
3. 프로젝트 폴더에서 PHP 웹 서버를 실행한다. XAMPP Apache를 사용할 때는 `public/`을 문서 루트로 두어도 된다.

```sh
php -S 127.0.0.1:8765 -t public
```

HOME(`index.php`)과 EXPLORE(`explore.php`)는 공개 페이지다. EXPLORE에서 장르를 선택하고 가입 또는 로그인하면 선택이 보호된 `workspace.php`로 돌아온다. 현재 작업 공간은 계정과 선택을 확인하는 진입점이며 세계관 생성·관리는 04단계에서 연결한다. 가입·로그인은 브라우저 Fetch와 일반 폼 제출을 모두 지원한다. 로그아웃은 CSRF 토큰이 있는 POST로 처리한다.

가입·로그인 속도 제한은 MySQL에 저장한다. 가입은 IP별 30분 동안 8회, 로그인은 IP별 15분 동안 25회와 이메일별 15분 동안 8회다. 세션은 로그인 직후 ID를 재발급하고 유휴 2시간 뒤 만료한다. `APP_ENV=production`에서는 HTTPS를 요구하고 Secure 세션 쿠키를 사용한다.

### 이미지와 영상

2026-11-26 (KST)에 ImageGen으로 두 원본을 생성했다. 포털은 어두운 협곡·보랏빛 타원형 균열·은색 궤도·먼 행성을 지정한 가로 포스터다. 장르 이미지는 판타지 성·미래 도시·현대 거리·폐허·다크 판타지 성당·시대극 궁궐을 3×2 원본으로 생성한 뒤 셀별로 잘랐다. 런타임에는 `public/assets/images/`의 WebP 파일만 사용한다. EXPLORE의 샘플 세 개는 별도로 작성한 공개 예시다.

HOME의 포털 콘셉트 이미지는 개발용 포스터다. 생성형 영상의 HTTPS MP4·WebM 주소를 비공개 설정의 `HERO_VIDEO_URL`에 넣으면 HERO에서 재생된다. 영상의 출처를 CSP에 반영하며, 모션 감소 설정에서는 포스터가 기본이다. 직접 파일 주소를 쓰기 어렵다면 임베드가 허용된 YouTube 공개 또는 일부 공개 영상의 공유 링크를 같은 항목에 넣는다. HOME에서 **포털 영상 보기**를 누르면 HERO 바로 아래 공식 플레이어로 이동한다.

사용자가 제공한 생성형 MP4를 1280×720, H.264, 7.92초, 약 4.2MB의 무음 웹 버전으로 인코딩했다. 로컬 Chromium에서 이 파일의 무음 반복 재생·정지와 모션 감소 환경에서의 수동 재생을 확인했다. 아래 주소를 운영 설정의 `HERO_VIDEO_URL`에 입력한 후 실제 사이트에서 재생을 확인한다. [포털 영상 MP4](https://raw.githubusercontent.com/05SUA/-/loreverse-submission/LOREVERSE/submission/hero-portal.mp4)

### 검증 결과

- PHP 8.4.28 및 JavaScript 문법 검사 통과. 운영 DB와 분리한 MariaDB 11.8.9에서 신규 스키마와 03단계 SQL을 적용했다.
- Chromium 1440·1024·768·390·360px에서 공개 네 페이지를 확인했다. 가로 넘침, 누락된 CSS·JS·이미지 응답, 페이지 JavaScript 오류 없이 동작했다.
- 실제 DB에서 이메일 정규화·중복 거부·비밀번호 해시 저장과 확인, 정상 로그인, 존재하지 않는 계정과 잘못된 비밀번호의 같은 응답, 요청 제한, CSRF 거부, 세션 ID 재발급, 외부 복귀 경로 거부, 로그아웃과 접근 차단을 확인했다. JavaScript를 끈 상태의 로그인·로그아웃도 통과했다.
- 03단계 파일의 InfinityFree 배포 및 운영 회원가입 점검은 이후 배포 단계에서 수행한다.

## 04단계 세계관·작업 공간

03단계까지 준비한 DB에 `database/migrations/004_world_update_timestamp.sql`을 적용한다. 기존 세계관의 수정 시각에 마이크로초 정밀도를 추가해 같은 초에 여러 화면에서 수정하더라도 이전 버전의 요청을 거부할 수 있게 한다. 새 DB 설치 순서는 `schema.sql`, 003 SQL, 004 SQL이다.

- HOME이나 EXPLORE에서 장르를 선택하면 로그인 후 `world-create.php`에서 그대로 이어진다. 5단계에서 기본 조건을 입력하고, Gemini로 초안을 받거나 직접 작성할 수 있다. 고정한 항목은 서버에서 보존하며 부분 재생성은 선택한 항목만 변경한다. 결과가 와도 사용자가 **세계관 저장**을 누르기 전에는 세계관 레코드가 생성되지 않는다.
- `workspace.php`는 자신의 실제 데이터 집계와 최근 세계관을 표시하고, **내 세계관**에서 장르 필터·검색·정렬·페이지 이동을 지원한다. `world.php`에서 소유자만 설정, 커버, 편집·복제·삭제에 접근할 수 있다. 복제는 설정을 복사하며 사용자 커버와 연결된 창작 데이터는 원본에 남는다.
- 커버는 JPG·PNG·WebP, 최대 2MB와 4096×4096을 지원한다. 먼저 설정을 저장한 뒤 커버를 작업하며, 업로드에 실패하면 설정을 유지하고 재시도할 수 있다. 호스팅 PHP에서 비공개 `private/uploads/worlds/`를 생성하고 쓸 수 있어야 한다. 브라우저에는 소유권을 확인하는 `world-cover.php`로 이미지를 제공한다.
- 서버의 Gemini 키·모델은 기존 비공개 설정을 사용한다. 생성 요청은 회원별 UTC 일일 사용량과 한 번에 하나만 진행되는 제한을 적용한다. 응답 대기를 취소하면 브라우저 입력은 유지되며 이미 제출된 외부 요청은 잠시 계속 처리될 수 있다.

로컬 계약 검증은 `php tests/stage04-world-contract.php`로 실행한다. 이 검사는 고정값과 부분 재생성 병합, 입력 검증, 응답 자료형을 점검한다. Gemini 정상 응답을 확인하려면 실제 키·모델과 HTTPS 접근이 있는 환경에서 장르·입력을 바꿔 두 번 생성하고, 이후 한 항목을 재생성해 다른 값이 유지되는지 확인한다.

### 검증 결과

- 분리된 MariaDB에 기본 스키마 및 003·004 SQL을 적용했다. Chromium에서 장르 선택 후 회원가입 복귀, 5단계 입력 유지, 잘못된 커버 파일 거부 후 재시도, 실제 저장과 재로그인 복원, 편집 충돌, 검색·필터, 설정 복제, 확인 후 삭제를 점검했다.
- 두 번째 계정에서 원본의 목록·상세·저장·복제·삭제와 커버 읽기를 차단했다. 잘못된 CSRF 요청과 확인 없는 삭제도 거부했다.
- 가져온 예시 자료로 PHP 검증 계약을 점검하고, 실제 MariaDB로 AI 예약 중복·동시 요청·만료·실패 기록·UTC 일일 제한을 확인했다. Gemini 설정 누락 시 기존 초안 입력과 수동 작성 내용을 유지한다.
- 가입 직후 겹치는 브라우저 요청으로 새 로그인 쿠키가 바뀌는 문제를 수정했다. 이전 세션에는 인증 정보가 남지 않으며, 새 브라우저 세션으로 장르 선택을 이어갈 수 있음을 확인했다.

### InfinityFree 04단계 사전 진단 (2026-11-26, KST)

사용자가 `https://loreverse.42web.io`에서 실행한 임시 PHP 진단 결과를 전달했다.

- `db_migrations: true`로 03단계 요청 제한 테이블과 04단계 세계관 수정 시각 정밀도가 운영 DB에 반영됐음을 확인했다.
- 서버에 설정된 API 키와 모델로 Gemini 모델 정보 조회가 HTTP 200을 반환했고, 조회 결과에 `generateContent` 지원이 포함됐다. 생성 호출은 Google 오류 응답 형식의 `503 UNAVAILABLE`을 반복 반환했다.
- 사용자는 Google AI Studio에서 같은 모델의 생성 응답을 확인했다.
- 운영 서버에서 JSON 스키마 없이 짧은 문장 생성을 시험해 Google의 `HTTP 503 UNAVAILABLE`을 받았다. 사용자가 같은 키·모델·REST 요청을 Google Colab에서 실행해 같은 응답을 확인했다. API 프로젝트나 모델의 생성 가용성은 추가 확인이 필요하다.
- 별도 프로젝트 키로 Colab에서 모델 조회 HTTP 200과 `generateContent` 지원을 확인했다. `v1beta` 짧은 문장 생성은 1.8초에 `503 UNAVAILABLE`을 반환했다. 같은 키·모델·요청으로 공식 안정 버전 `v1`의 모델 조회는 0.1초에 HTTP 200을 반환했고, 생성 요청은 55.1초까지 HTTP 응답 헤더를 받지 못해 시간 초과됐다.

### InfinityFree 시연용 패키지

1. InfinityFree File Manager에서 LOREVERSE 도메인의 `htdocs`로 이동해 `LOREVERSE-InfinityFree-Upload.zip`을 **Upload & Unzip**으로 올린다. 압축을 풀면 `htdocs/loreverse`가 생긴다.
2. 패키지는 같은 도메인의 `htdocs/loreverse-stage01/private/config.local.php`에서 DB·Gemini·영상 설정을 읽는다. 003·004 변경을 적용한 기존 DB를 사용한다. 영상 링크는 해당 비공개 설정의 `HERO_VIDEO_URL`에 넣는다.
3. 배포한 도메인의 `/loreverse/private/_access-check.txt`에 HTTP 접근이 차단되는지 확인한다. `/loreverse/public/index.php`에서 영상 재생을 확인한다. 로그인 후 **세계관 만들기 → AI 장르 추천 → 이 장르 선택 → 직접 작성 → 세계관 저장**을 시연한다. 재로그인 후 저장한 세계관을 확인하고 공개 홈을 제출 주소로 기록한다.

### InfinityFree 시연 앱 운영 확인 (2026-11-26, KST)

- 사용자가 InfinityFree에서 공개 홈 접속과 비공개 확인 파일의 접근 차단을 확인했다. [운영 사이트](https://loreverse.42web.io/loreverse/public/index.php)
- 사용자가 운영 사이트에서 로그인한 뒤 세계관을 직접 작성해 저장하고, 재로그인 후 **내 세계관**에서 복원되는 것을 확인했다.
- 사용자가 운영 홈에서 생성형 포털 MP4가 첫 화면 배경으로 재생되는 것을 확인했다. [포털 영상 MP4](https://raw.githubusercontent.com/05SUA/-/loreverse-submission/LOREVERSE/submission/hero-portal.mp4)
- 일반 브라우저의 운영 세계관 빌더에서 사용자가 실제 Gemini 장르 추천 결과 `판타지`를 확인했다. 이후 단계의 **Gemini로 초안 생성**은 여전히 실패했다.

### 과제용 Gemini AI 장르 추천

- 세계관 빌더 첫 단계에서 **세계관 아이디어 → AI 장르 추천**을 누른다. 기본 HTML 폼이 같은 페이지의 PHP로 제출되고, PHP가 기존 비공개 키로 Gemini 배치 임베딩을 호출한다. 아이디어와 기존 장르 설명 6개의 벡터를 코사인 유사도로 비교해 가장 가까운 장르 하나를 표시한다. 사용자가 **이 장르 선택**을 눌러 장르와 특징란에 아이디어를 적용한다.
- 추천 요청에 로그인·CSRF·입력 검증, 기존 AI의 사용자별 동시 요청 제한과 UTC 일일 한도를 적용한다. 세계관은 기존 **직접 작성 → 세계관 저장**으로 MySQL에 반영된다.
- 기존 시연 사이트에는 `LOREVERSE-AI-Genre-Update.zip`을 File Manager에서 `/loreverse.42web.io/htdocs`에 **Upload & Unzip**한다. 압축에는 수정 PHP와 JS 7개가 들어 있다. 세계관 빌더는 스크립트 내용에 따라 버전이 붙은 주소를 사용한다. 생성형 영상과 DB 설정은 패키지가 읽던 비공개 파일을 사용한다.
- 이전 업데이트를 올린 사이트에서 추천 버튼이 반응하지 않으면 `loreverse/public/world-create.php` 한 파일을 최신 소스로 교체해도 기본 HTML 폼이 적용된다. 추천 폼은 페이지를 다시 열어 첫 단계의 아이디어와 장르를 이어가므로, 추천과 선택을 마친 뒤 다음 단계의 값을 입력한다.
- 로컬 HTTPS에서 Gemini 테스트 응답으로 추천, 오류 처리와 사용량 제어를 검증했다. 운영 사이트의 실제 추천 결과는 위에 기록했다. 추천 장르를 선택한 새 세계관을 저장하고 다시 여는 과제용 시연 흐름을 확인한다.
