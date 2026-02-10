# bare-photos

Sony a7C などのカメラから FTP で同一フォルダに集約された写真を、ローカル/セルフホストで「なんちゃって Google フォト」として見るための最小 PHP アプリです。

## この実装でできること

- `./photos/` を再帰走査して、サブフォルダ内も含めて**ファイル名パスの自然順昇順**で一覧表示
- サムネグリッド表示 + クリックでモーダル拡大
- 一覧/詳細の両方でファイル名表示
- 対応形式: `jpg / jpeg / png / webp / gif` + RAW (`.ARW` など)
- RAW サムネ生成
  1. 同名 JPG/JPEG（例: `DSC0001.ARW` と `DSC0001.JPG`）があればそれを優先利用
  2. `exiftool` で埋め込みプレビューを抽出（`PreviewImage` → `JpgFromRaw`）
  3. 失敗時は「サムネなし」を UI で明示
- `thumbs/` にサムネキャッシュ保存
- lazy-load、総枚数表示、ローディング表示、壊れた画像の表示
- 簡易認証（`.env` で Basic 認証）
- 追加機能: 拡張子フィルタ（すべて / 画像のみ / RAW のみ）
- お気に入り（★）機能 + お気に入りのみ表示フィルタ
- 単体削除（詳細モーダル）+ 複数選択削除（一覧チェックボックス）
- 削除前の確認ダイアログ
- 詳細モーダルに「元データをダウンロード」ボタンを追加（RAW はファイル保存向け、画像は長押し保存も可能）
- 詳細モーダルに撮影日時（EXIF / ExifTool / ファイル更新時刻フォールバック）を表示

## 技術選定（なぜ PHP か）

- ロリポップ等の共有レンタルサーバで扱いやすい
- 追加サーバ（Node, Python ワーカー）なしで単体運用しやすい
- `php -S` でローカル検証が簡単
- 画像処理は GD/Imagick があれば活用、なくてもフォールバック可能

## フォルダ構成

```txt
bare-photos/
├─ public/
│  ├─ index.php
│  └─ api/
│     ├─ photos.php
│     ├─ file.php
│     └─ thumb.php
├─ src/
│  ├─ auth.php
│  ├─ config.php
│  ├─ env.php
│  └─ photos.php
├─ photos/      # FTP 取り込み先（原本）
├─ thumbs/      # 生成サムネ
├─ data/        # お気に入り情報（favorites.json）
├─ .env.example
└─ README.md
```

## セットアップ（コピペ用）

```bash
cd /workspace/bare-photos
cp .env.example .env
mkdir -p photos thumbs data
php -S 0.0.0.0:8080 -t public
```

ブラウザで `http://localhost:8080` を開きます。

## 使い方

1. カメラ/FTP の保存先を `photos/` に向ける（サブフォルダ配下も表示対象）
2. 一覧がファイル名パスの自然順（`a/1, a/2, a/10`）で表示される
3. サムネ右上の `☆/★` でお気に入りを切り替え、ツールバーの「お気に入りのみ」で絞り込み
4. チェックボックス選択 + 「選択を削除」で複数削除（確認ダイアログあり）
5. クリックするとモーダル表示。モーダルから単体削除も可能（確認ダイアログあり）
6. RAW は同名 JPG/JPEG があればそれを優先してサムネ・プレビュー表示
7. 同名 JPG/JPEG が無い場合は埋め込み JPEG 抽出を試行し、不可なら「RAWサムネ生成不可（同名JPG/JPEGまたはExifToolが必要）」を表示
8. モーダルの「元データをダウンロード」で原本を保存（RAW はファイルとして保存、画像は長押し保存も可）
9. モーダル下部に撮影日時を小さく表示

## 認証（必須対策）

### 1) ローカル開発: `.env` の簡易 Basic 認証

`.env` に設定（値は必ず変更）:

```dotenv
APP_BASIC_USER=viewer
APP_BASIC_PASS=change-me
```

未設定（空）の場合は認証無しで動作します。

### 2) 本番: Web サーバレイヤの Basic 認証（推奨）

#### Apache (.htaccess 例)

```apacheconf
AuthType Basic
AuthName "bare-photos"
AuthUserFile /home/your-user/.htpasswd
Require valid-user
```

`.htpasswd` は `htpasswd` コマンドで作成し、**リポジトリ外**に配置してください。

## RAW サムネ生成の仕様

- 優先 1: 同名 JPG/JPEG を利用（`DSC0001.ARW` ↔ `DSC0001.JPG` / `.JPEG`）
- 優先 2: `exiftool -b -PreviewImage <RAW>`
- 優先 3: `exiftool -b -JpgFromRaw <RAW>`
- 抽出 JPEG または同名 JPG/JPEG を縮小して `thumbs/<sha1(filename)>.jpg` として保存
- 失敗時は UI で `RAWサムネ生成不可（同名JPG/JPEGまたはExifToolが必要）` と表示（ファイル名は一覧される）

### ロリポップでの現実的な対策

ロリポップの共有サーバでは `exiftool` を新規導入できないことが多いです。その場合でも次の運用で改善できます。

1. **カメラを RAW+JPEG 記録にする**（推奨）
   - 同名 JPG/JPEG が `photos/` に入るため、RAW のサムネ/プレビューとして使えます。
2. FTP 転送時に JPG も同時送信する
3. どうしても RAW のみの場合は、サムネ無し表示を許容（本アプリはファイル名表示を維持）

> 注: 本実装は RAW の本格現像は行いません。埋め込みプレビュー優先の軽量設計です。

## パフォーマンス上の注意

- サムネは `thumbs/` にキャッシュ
- `<img loading="lazy">` で遅延読み込み
- GD/Imagick が無い環境では通常画像のサムネ生成に失敗し、元画像を一覧表示にフォールバックすることがあります


## トラブルシュート（「読み込み失敗: HTTP 404」が出る場合）

以下を順に確認してください。

1. `public/` をドキュメントルートにしているか
   - ローカルは `php -S 0.0.0.0:8080 -t public`
2. サブディレクトリ配置の場合
   - 例: `https://example.com/bare-photos/public/`
   - 末尾 `/` の有無に関係なく動くよう、ページのベースパスから `.../public/api/...` を解決する実装です。
3. API 直アクセスで疎通確認

```bash
curl -i http://localhost:8080/api/photos.php
```

`HTTP/1.1 200 OK` と JSON が返れば正常です。

4. 認証有効時に 401/404 を取り違えていないか
   - `.env` で認証を有効化していると、認証なしアクセスは `401 Unauthorized` になります。

## API

- `GET /api/photos.php?filter=all|image|raw&favorites=0|1`
  - `total`, `items[]`, `favoritesOnly` を返す
  - `items[]` に `path`, `filename`, `isFavorite`, `thumbnailUrl`, `previewUrl` などを含む
- `GET /api/file.php?path=<relative_path>` 原画像/RAW 配信（`name` 互換も維持）
  - `download=1` を付けると `Content-Disposition: attachment` でダウンロード
- `GET /api/thumb.php?path=<relative_path>` サムネ配信（`name` 互換も維持）
- `POST /api/favorite.php` お気に入り切替
  - body: `{ "path": "sub/DSC0001.ARW", "favorite": true|false }`
- `POST /api/delete.php` 単体/複数削除
  - body: `{ "paths": ["a.jpg", "sub/b.ARW"] }`

## Assumptions

- FTP が `photos/` へ直接書き込める前提
- ファイル削除や重複解決は対象外（最小構成のため）
- RAW 現像エンジン（dcraw/libraw 等）が無い環境を想定し、埋め込み JPEG 抽出失敗時は「サムネ無し」で許容
- まず閲覧用途を優先し、編集/メタデータ検索は対象外
