# 謎解きスタンプラリー 2026

このREADMEは、プログラミング経験がない人でも、このプロジェクトを起動し、ストーリー本文や分岐を変更できるように書いています。

分からない操作があっても、まずは書いてあるコマンドを上から順番に実行してください。

---

## 1. このプロジェクトで使っているもの

| 名前 | 役割 |
| --- | --- |
| HTML / CSS / JavaScript | ブラウザに表示する画面とゲームの動き |
| PHP | LINEログイン、QRスタンプ、Exment連携用のサーバー |
| Composer | PHPライブラリを管理する道具 |
| Node.js / npm | JavaScriptのテストを実行する道具 |
| Exment | 本番のスタンプ、選択肢、エンディングの保存先 |
| LINEログイン | 本番ユーザーの認証 |

プロジェクトの実際のフォルダは次です。

```text
C:\Users\toufu\Documents\desktop\Codes\Web\CloverFes2026_DigitalStampRally\stamp-rally-test
```

以下のコマンドは、基本的にこのフォルダで実行します。

---

## 2. 最初に必要なソフト

次のコマンドで確認できます。

```powershell
php -v
composer --version
node -v
npm -v
git --version
```

このプロジェクトで確認済みの環境:

- PHP 8.3.36
- Composer
- Node.js / npm
- Git

`php -v` が表示されない場合は、PHPがインストールされていないか、PATHが設定されていません。

注意: ComposerはPHP本体を更新する道具ではありません。PHP本体の更新はWindows側のPHPを更新します。

---

## 3. プロジェクトを取得する

### GitHubから初めて取得する場合

PowerShellを開き、プロジェクトを置きたい場所へ移動します。

```powershell
cd C:\Users\toufu\Documents\desktop\Codes\Web
```

次にcloneします。

```powershell
git clone https://github.com/suk459441-cpu/stamp-rally-test.git
```

cloneが終わったらプロジェクトへ移動します。

```powershell
cd stamp-rally-test
```

### すでに取得済みの場合

プロジェクトフォルダへ移動します。

```powershell
cd C:\Users\toufu\Documents\desktop\Codes\Web\CloverFes2026_DigitalStampRally\stamp-rally-test
```

現在のブランチを確認します。

```powershell
git branch --show-current
git status
```

---

## 4. Gitで最新コードを取得する方法

GitHub上の最新変更を取得する操作が `git pull` です。

まず、変更していないことを確認します。

```powershell
git status
```

`working tree clean` と表示されていれば、次を実行できます。

```powershell
git pull
```

特定のブランチを更新する場合:

```powershell
git pull origin kamakuramakla_10182026_feeature/branching_by_ans
```

### Git用語の簡単な説明

- `origin`: GitHub上のリモートリポジトリ名
- `main`: 本番の基準ブランチ
- `branch`: 作業用の分岐
- `git pull`: GitHubから最新変更を取得
- `git status`: 自分の変更状況を確認
- `git diff`: 何を変更したか確認
- `git add`: コミット対象に登録
- `git commit`: 自分の変更を記録
- `git push`: GitHubへ送信

### 変更を消さずにpullするために

変更中にいきなり `git pull` しないでください。先に状態を確認します。

```powershell
git status
git diff
```

自分の変更を残したまま最新コードを取得したい場合は、先にコミットするか、作業内容を別途保存してからpullします。

`.env` はGit管理外のローカル設定ファイルなので、通常の `git pull` では上書きされません。

---

## 5. PHPサーバーを起動する

プロジェクトフォルダで次を実行します。

```powershell
php -S localhost:8000 -t . public/server.php
```

次の表示が出れば起動成功です。

```text
PHP Development Server (http://localhost:8000) started
```

サーバーを停止するときは、同じPowerShellで次を押します。

```text
Ctrl + C
```

---

## 6. LINEを使わずに画面を確認する

開発中はLINEログインを使わず、デモモードで確認できます。

```text
http://localhost:8000/?demo=1
```

### デモ用スタンプ取得

スタンプは順番に取得します。

```text
http://localhost:8000/?demo=1&token=dev-stamp-token-1
http://localhost:8000/?demo=1&token=dev-stamp-token-2
http://localhost:8000/?demo=1&token=dev-stamp-token-3
http://localhost:8000/?demo=1&token=dev-stamp-token-4
http://localhost:8000/?demo=1&token=dev-stamp-token-5
```

操作の流れ:

1. スタンプ1を取得
2. ストーリーを読み、選択肢を選ぶ
3. スタンプ2を取得
4. 次の選択肢を選ぶ
5. スタンプ3、4、5も同じように進める
6. 全スタンプ取得後にエンディングへ進む

デモモードの保存先はブラウザのlocalStorageです。DBやExmentには保存されません。

### 進行状況を最初からやり直す

画面下部の「端末ログ初期化（リセット）」を押します。

または、ブラウザの開発者ツールからlocalStorageを削除します。通常は画面のリセットボタンを使ってください。

---

## 7. LINE連携版とデモ版の違い

ストーリー、分岐、スタンプ取得順、チェックポイント、全スタンプ取得条件は共通です。

| 項目 | LINE / Exment版 | デモ版 |
| --- | --- | --- |
| ログイン | LINEログインが必要 | 不要 |
| スタンプ保存 | Exment | localStorage |
| 選択肢保存 | Exment | localStorage |
| エンディング保存 | Exment | localStorage |
| QRトークン | サーバーで検証 | `dev-stamp-token-1`〜`5`をローカル検証 |
| DB通信 | あり | なし |
| 周回数 | Exmentの `loop_count` | localStorageの `loopCount` |

1周目と2周目をクリアするとスタンプをリセットして次の周回へ進みます。3周目の最終エンディングでは、LINE/Exment版は `cleared_at` を保存し、デモ版は `clearedAt` をlocalStorageへ保存します。

通常の分岐確認はデモモードで行い、本番保存やLINE認証はLINE連携版で確認します。

---

## 8. セリフを変更する方法

セリフの原稿は、Excelを編集してからCSVとして取り込む運用です。

### 手順

1. Excelの本文を変更する
2. Excelの対象シートをUTF-8のCSVとして保存する
3. CSVを指定してインポートする
4. テストを実行する
5. ブラウザを再読み込みする

インポートコマンド:

```powershell
node tools/import-story-csv.js "C:\path\to\story.csv"
```

例えばDownloadsにあるCSVなら:

```powershell
$csv = Get-ChildItem C:\Users\toufu\Downloads -Filter '*.csv' |
    Where-Object Name -like '*謎スタ*' |
    Select-Object -First 1 -ExpandProperty FullName

node tools/import-story-csv.js $csv
```

### CSVの列

現在の原稿では、主に次の列を使います。

- A列: 原稿のノード番号
- B列: 本文
- L列: 選択肢A
- N列: 選択肢B

原稿の番号は画面に表示する番号ではありません。内部で本文を対応づけるために使います。

### 直接編集してはいけないファイル

`json/storyGraph.json` はCSVから生成されるファイルです。

本文を直接 `storyGraph.json` に書いても、次回インポート時に上書きされます。本文を変えるときは、必ずExcelまたはCSVを変更してください。

### 話者を変更する方法

話者判定は次のファイルにあります。

```text
tools/import-story-csv.js
```

主に次の関数を変更します。

```js
senderForQuote()
```

発言内容から判定するルールは `contentHints` にあります。

```js
[/私を信じて/, 'kiriko'],
[/まだ動いてるの/, 'bunji'],
[/調査員の皆様へ/, 'guideAi']
```

書式は次のとおりです。

```js
[/セリフ内に含まれる特徴的な文章/, '話者ID']
```

話者IDは次のファイルのキーと一致させます。

```text
json/characters.json
```

例:

```json
{
  "kiriko": {
    "name": "キリコ",
    "avatar": "👧",
    "type": "character"
  }
}
```

話者が判定できない引用は、誤った人物の吹き出しにせず地の文として表示されます。

---

## 9. 地の文の表示を変更する方法

地の文は人物の吹き出しではなく、コンピューターのログ表示として扱います。

生成データ:

```json
{
  "type": "narrative",
  "text": "ここは地の文です。"
}
```

見た目を変更するファイル:

```text
css/style.css
```

変更するCSS:

```css
.narrative-msg
```

現在は次の見た目になっています。

- `>_` プロンプト
- 等幅フォント
- 暗い端末風背景
- 左から右へ表示されるアニメーション
- 点滅カーソル

表示処理自体を変える場合は、次のメソッドを確認します。

```text
js/app.js
advanceDialogue()
```

---

## 10. 分岐を変更する方法

分岐先は次のファイルで変更します。

```text
tools/import-story-csv.js
```

次の `transitions` を編集します。

```js
const transitions = {
    '1': { A: '2', B: '3' },
    '2': { A: '4', B: '5' }
};
```

例えば、ノード2のAをノード6へ変更する場合:

```js
'2': { A: '6', B: '5' }
```

変更後は必ず再生成します。

```powershell
node tools/import-story-csv.js "C:\path\to\story.csv"
```

### 分岐で守ること

- `next` は存在するノードへ設定する
- 次の区間へ進む分岐は、次のスタンプ取得後に再開する
- `next: null` のボタンを作らない
- 最終ノードには `endingKey` を設定する
- ノード番号を画面表示のチェックポイント名として使わない

全ノードの到達性と遷移先はテストで確認できます。

---

## 11. チェックポイントとスタンプ条件を変更する方法

区間の分類は次の関数で決まります。

```text
tools/import-story-csv.js
```

変更対象:

- `checkpointFor()`: START / CHECKPOINT 01〜03 / GOAL
- `stampGateFor()`: ノードに必要なスタンプ番号

スタンプ取得後に開く区間入口は、次のメソッドです。

```text
js/app.js
openStampStory()
```

入口の対応表:

```js
const entryNodes = {
    1: '1',
    2: '2',
    3: '4',
    4: '8',
    5: null
};
```

スタンプ5は、チェックポイント3の選択結果がない場合にエンディングへ進まないよう、入口を持たせていません。

---

## 12. エンディングを追加する方法

エンディングを追加する場合は、最低でも次の3か所をそろえます。

### 1. ノードとエンディングの対応

```text
tools/import-story-csv.js
```

```js
const endings = {
    '25': 'NEW_ENDING'
};
```

### 2. エンディング表示名

```text
json/endingMaster.json
```

```json
{
  "NEW_ENDING": {
    "id": "END-25",
    "name": "新しい結末",
    "desc": "新しいエンディングの説明。"
  }
}
```

### 3. LINE/Exmentの許可リスト

```text
src/Services/Exment/StampRallyRecordRepository.php
```

`ALLOWED_ENDING_IDS` に `END-25` を追加します。

これを忘れると、デモモードでは表示できても、LINE連携版ではExment保存時に `invalid_ending` になります。

---

## 13. テストの実行

JavaScriptテスト:

```powershell
npm test
```

PHPテスト:

```powershell
php test/php/run.php
```

Composerの環境確認:

```powershell
composer check-platform-reqs
```

すべてまとめて確認:

```powershell
npm run test:all
```

変更後は最低限、次の3つを実行してください。

```powershell
npm test
php test/php/run.php
composer check-platform-reqs
```

---

## 14. よくある問題

### `ERR_CONNECTION_REFUSED`

PHPサーバーが停止しています。

```powershell
php -S localhost:8000 -t . public/server.php
```

### スタンプを取得したのに同じ画面が表示される

次を確認してください。

1. ブラウザを `Ctrl + F5` で再読み込み
2. localStorageをリセット
3. スタンプ1から順番に取得
4. 選択肢を選んでから次のスタンプを取得

### スタンプ5だけでエンディングになる

スタンプ5は単独ではゴールへ進みません。先にチェックポイント3で分岐を選ぶ必要があります。古いJavaScriptが残っている場合は `Ctrl + F5` を押してください。

### `invalid_ending` が出る

`json/endingMaster.json` と、PHPの `ALLOWED_ENDING_IDS` が一致しているか確認してください。

### LINEログインでlocalhost:3000へ移動する

`.env` の `NUXT_PUBLIC_SITE_URL`、PHPサーバーのポート、LINE DevelopersのコールバックURLが同じである必要があります。

### 進行状態を最初からやり直したい

画面の「端末ログ初期化（リセット）」を押してください。localStorageに古い `loopCount`、スタンプ、保留中の分岐が残っていると、意図しない場所から再開します。

---

## 15. 変更をGitHubへ送る

変更内容を確認します。

```powershell
git status
git diff
```

変更を登録します。

```powershell
git add README.md js/app.js css/style.css json/storyGraph.json test/stamp-rally.test.js
git commit -m "ストーリー分岐を更新"
```

GitHubへ送ります。

```powershell
git push
```

作業前にGitHubの最新変更を取得する場合:

```powershell
git pull
```

初心者の場合は、変更前に必ず `git status` と `git diff` を確認してください。
