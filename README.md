# 謎解きスタンプラリー

## 開発用URL

LINEログインを使わずに画面を確認する場合は、PHPサーバーを起動して次のURLを開きます。

```text
http://localhost:8000/?demo=1
```

デモモードのスタンプ取得は、次の開発用トークンをURLに付けて行います。

```text
http://localhost:8000/?demo=1&token=dev-stamp-token-1
http://localhost:8000/?demo=1&token=dev-stamp-token-2
http://localhost:8000/?demo=1&token=dev-stamp-token-3
http://localhost:8000/?demo=1&token=dev-stamp-token-4
http://localhost:8000/?demo=1&token=dev-stamp-token-5
```

スタンプは `1 -> 2 -> 3 -> 4 -> 5` の順番で取得します。デモモードではDBやExmentへ保存せず、ブラウザの `localStorage` だけを更新します。

## LINE連携版とデモ版の差分

ストーリー、分岐、スタンプ順、全5スタンプ取得前のエンディング禁止、チェックポイント区間は両モードで共通です。通常の動作確認はデモ版で行い、LINE連携版では保存先と認証だけが変わります。

| 項目 | LINE/Exment版 | `?demo=1` デモ版 |
| --- | --- | --- |
| LINEログイン | 必須 | 不要 |
| スタンプ保存 | Exmentのユーザーレコード | ブラウザのlocalStorage |
| 選択肢保存 | Exmentの周回別カラム | localStorage |
| エンディング保存 | Exmentの収集エンディング | localStorage |
| QRトークン | サーバーで検証 | `dev-stamp-token-1`〜`5`をローカル検証 |
| 取得順 | 1→2→3→4→5 | 1→2→3→4→5 |
| DB/API通信 | あり | なし |

周回処理も同じです。1周目・2周目をクリアするとスタンプを空にして次周を開始し、3周目で `END-AI` を選ぶと最終クリアとして扱います。LINE/Exment版は `cleared_at` を保存し、3周目の最終クリアではスタンプ記録を保持します。デモ版も `clearedAt` をlocalStorageに保存し、同じ状態を再現します。

LINE連携版のエンディングIDは、`json/endingMaster.json`、`tools/import-story-csv.js` の `endings`、PHPの `src/Services/Exment/StampRallyRecordRepository.php` の許可リストで一致させてください。

## 起動方法

プロジェクトディレクトリで実行します。

```powershell
php -S localhost:8000 -t . public/server.php
```

テスト:

```powershell
npm test
php test/php/run.php
```

## データの役割

| ファイル | 役割 |
| --- | --- |
| `json/storyGraph.json` | ノード、選択肢、遷移先、チェックポイント、スタンプゲート |
| `json/dialogues.json` | 既存の短いチェックポイント会話 |
| `json/dialoguesLoop2.json` | 旧データ。現在のExcel分岐グラフでは使用しない |
| `json/characters.json` | 発言者名、アイコン、表示種別 |
| `json/endingMaster.json` | エンディング名と説明 |
| `tools/import-story-csv.js` | Excelから書き出したCSVを分岐グラフへ変換するツール |
| `js/app.js` | スタンプ取得、ノード遷移、会話表示、エンディング表示 |
| `css/style.css` | 吹き出しと地の文パネルの見た目 |

`storyGraph.json` は生成ファイルです。本文や選択肢を変更した場合は、原稿CSVを修正してからインポートし直してください。

## セリフを変更する

### Excel原稿を変更する場合

1. Excelの本文を編集します。
2. シートをUTF-8のCSVとして保存します。
3. CSVを指定してインポートします。

```powershell
node tools/import-story-csv.js "C:\path\to\story.csv"
```

インポートすると `json/storyGraph.json` が上書きされます。

CSVの主な列は次のとおりです。

- A列: 原稿上のノード番号。画面表示上の意味は持たせません。
- B列: 本文。改行を含めて保持します。
- L列: 選択肢A
- N列: 選択肢B

### 発言者の判定を変更する場合

発言者判定は `tools/import-story-csv.js` の `senderForQuote()` で行っています。

優先順位は次のとおりです。

1. セリフの内容に対する `contentHints`
2. セリフと同じ行にある人物名
3. 直前行にある人物名
4. 判定できない場合は地の文

例えば、キリコの固有表現を追加する場合:

```js
[/この表現/, 'kiriko']
```

発言者IDは `json/characters.json` のキーと一致させます。

```json
{
  "kiriko": {
    "name": "キリコ",
    "avatar": "👧",
    "type": "character"
  }
}
```

人物を追加した場合は、`characters.json` と `senderForQuote()` の両方を更新してください。

### 地の文の表示を変更する場合

引用符のない文章、または発言者を判定できなかった引用は、次の形式で保存されます。

```json
{
  "type": "narrative",
  "text": "ここは地の文です。"
}
```

地の文のUIは `css/style.css` の `.narrative-msg` で変更します。表示処理は `js/app.js` の `advanceDialogue()` にあります。

## 分岐を変更する

分岐の元データは `tools/import-story-csv.js` の `transitions` です。

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

選択肢の表示文言はCSVのL列・N列から取得されます。遷移先は `transitions` の設定から取得されます。

### 分岐の注意点

- `next` は必ず存在するノードIDにする
- 次の区間へ進む場合は、次のスタンプ取得後に保留中のノードへ復帰する
- 空の選択肢は作らない
- 末端ノードには `endingKey` を設定する
- 番号は内部IDとして使うだけで、画面上のチェックポイント名には使わない

自動テストでは、全ノードがスタートから到達可能か、遷移先・会話・エンディングが存在するかを確認しています。

## チェックポイントとスタンプ条件を変更する

区間の対応は `tools/import-story-csv.js` の次の関数で管理しています。

- `checkpointFor()`: `START / CHECKPOINT 01〜03 / GOAL` の表示区間
- `stampGateFor()`: 各ノードに必要なスタンプ番号

スタンプ取得後に開く区間入口は `js/app.js` の `openStampStory()` です。

```js
const entryNodes = {
    1: '1',
    2: '2',
    3: '4',
    4: '8',
    5: '15'
};
```

Excelの青線の構成を変更する場合は、`checkpointFor()`、`stampGateFor()`、`entryNodes` を同じ設計に合わせて変更してください。

## エンディングを追加・変更する

1. `tools/import-story-csv.js` の `endings` にノードとエンディングキーを追加します。
2. `json/endingMaster.json` に名前と説明を追加します。
3. LINE/Exment連携でも保存する場合は、PHPの `src/Services/Exment/StampRallyRecordRepository.php` にある許可エンディングID一覧も更新します。
4. 全5スタンプ取得後に表示されることをテストします。

例:

```js
const endings = {
    '25': 'NEW_ENDING'
};
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

## 変更後の確認

最低限、次を実行してください。

```powershell
node tools/import-story-csv.js "C:\path\to\story.csv"
npm test
php test/php/run.php
```

ブラウザでは、localStorageの状態が残っていると古い進行状況が再開されます。ルートを最初から確認する場合は、画面のリセット操作を実行してから `?demo=1` を開いてください。
