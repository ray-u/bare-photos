<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';

requireAppAuth();
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($appBasePath === '' || $appBasePath === '.') {
    $appBasePath = '';
}
?><!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>bare-photos</title>
  <style>
    :root { color-scheme: dark; }
    body { margin: 0; font-family: system-ui, sans-serif; background: #0f1115; color: #e6e6e6; }
    .container { max-width: 1200px; margin: 0 auto; padding: 16px; }
    .toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
    select { padding: 8px; border-radius: 8px; border: 1px solid #444; background: #1b1f28; color: #fff; }
    #status { opacity: 0.9; }
    #grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
    .card { background: #171a22; border: 1px solid #2a3040; border-radius: 10px; overflow: hidden; min-height: 170px; display: flex; flex-direction: column; }
    .thumb-wrap { aspect-ratio: 4 / 3; background: #0a0d12; display: flex; align-items: center; justify-content: center; }
    .thumb-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .name { padding: 8px; font-size: 12px; line-break: anywhere; }
    .placeholder { font-size: 12px; padding: 8px; color: #ffcd9b; text-align: center; }
    .broken { color: #ff6f6f; }
    dialog { border: 1px solid #4d576f; border-radius: 10px; width: min(96vw, 1100px); background: #11151d; color: #fff; }
    dialog::backdrop { background: rgba(0,0,0,.7); }
    .modal-head { display:flex; justify-content: space-between; align-items:center; padding:8px 0; gap: 12px; }
    .modal-content { display: grid; gap: 8px; }
    .modal-content img { max-width: 100%; max-height: 80vh; display:block; margin:0 auto; }
    .meta { font-size: 12px; opacity: 0.8; text-align: center; }
    .modal-actions { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
    .hint { font-size: 12px; opacity: 0.75; margin: 0; text-align: center; }
    button, .button-link { padding: 6px 10px; border-radius: 8px; border: 1px solid #555; background: #1e2430; color: #fff; cursor: pointer; text-decoration: none; display: inline-block; }
  </style>
</head>
<body data-app-base="<?= htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8') ?>">
  <main class="container">
    <h1>bare-photos</h1>
    <div class="toolbar">
      <label for="filter">フィルタ:</label>
      <select id="filter">
        <option value="all">すべて</option>
        <option value="image">画像のみ</option>
        <option value="raw">RAWのみ</option>
      </select>
      <span id="status">読み込み中…</span>
      <span id="total"></span>
    </div>
    <section id="grid" aria-live="polite"></section>
  </main>

  <dialog id="viewer">
    <div class="modal-head">
      <strong id="viewerName"></strong>
      <button id="closeViewer">閉じる</button>
    </div>
    <div class="modal-content" id="viewerContent"></div>
  </dialog>

  <script>
    const appBase = document.body.dataset.appBase || '';
    const grid = document.getElementById('grid');
    const statusEl = document.getElementById('status');
    const totalEl = document.getElementById('total');
    const filterEl = document.getElementById('filter');
    const viewer = document.getElementById('viewer');
    const viewerName = document.getElementById('viewerName');
    const viewerContent = document.getElementById('viewerContent');

    async function loadPhotos() {
      const filter = filterEl.value;
      statusEl.textContent = '読み込み中…';
      grid.innerHTML = '';

      try {
        const res = await fetch(`${appBase}/api/photos.php?filter=${encodeURIComponent(filter)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        totalEl.textContent = `総枚数: ${data.total}`;
        statusEl.textContent = data.total === 0 ? '画像がありません。' : '読み込み完了';

        for (const item of data.items) {
          grid.appendChild(renderCard(item));
        }
      } catch (error) {
        statusEl.textContent = `読み込み失敗: ${error.message}`;
      }
    }

    function renderCard(item) {
      const card = document.createElement('article');
      card.className = 'card';

      const thumbWrap = document.createElement('div');
      thumbWrap.className = 'thumb-wrap';

      if (item.thumbnailUrl) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.src = item.thumbnailUrl;
        img.alt = item.filename;
        img.addEventListener('error', () => {
          thumbWrap.innerHTML = '<div class="placeholder broken">画像読み込みエラー</div>';
        });
        thumbWrap.appendChild(img);
      } else {
        thumbWrap.innerHTML = `<div class="placeholder">${item.thumbnailMessage || 'サムネなし'}</div>`;
      }

      const name = document.createElement('div');
      name.className = 'name';
      name.textContent = item.filename;

      card.appendChild(thumbWrap);
      card.appendChild(name);
      card.addEventListener('click', () => openViewer(item));
      return card;
    }

    function buildDownloadUrl(sourceUrl) {
      if (!sourceUrl) return '';
      const separator = sourceUrl.includes('?') ? '&' : '?';
      return `${sourceUrl}${separator}download=1`;
    }

    function openViewer(item) {
      viewerName.textContent = item.filename;
      viewerContent.innerHTML = '';

      if (item.previewUrl) {
        const img = document.createElement('img');
        img.src = item.previewUrl;
        img.alt = item.filename;
        img.loading = 'eager';
        img.addEventListener('error', () => {
          viewerContent.innerHTML = '<p class="broken">プレビューの読み込みに失敗しました。</p>';
        });
        viewerContent.appendChild(img);
      } else {
        viewerContent.innerHTML = `<p>${item.thumbnailMessage || 'プレビューなし'}</p>`;
      }

      if (item.takenAt && item.takenAt.value) {
        const takenAt = document.createElement('small');
        takenAt.className = 'meta';
        takenAt.textContent = `撮影日時: ${item.takenAt.value}`;
        viewerContent.appendChild(takenAt);
      }

      const actions = document.createElement('div');
      actions.className = 'modal-actions';

      const downloadLink = document.createElement('a');
      downloadLink.className = 'button-link';
      downloadLink.href = buildDownloadUrl(item.sourceUrl);
      downloadLink.textContent = '元データをダウンロード';
      downloadLink.setAttribute('download', item.filename);
      actions.appendChild(downloadLink);
      viewerContent.appendChild(actions);

      if (item.type === 'image') {
        const hint = document.createElement('p');
        hint.className = 'hint';
        hint.textContent = '画像は表示後に長押しして保存できます（端末の保存先はOS仕様に依存）。';
        viewerContent.appendChild(hint);
      }

      viewer.showModal();
    }

    document.getElementById('closeViewer').addEventListener('click', () => viewer.close());
    viewer.addEventListener('click', (event) => {
      if (event.target === viewer) viewer.close();
    });
    filterEl.addEventListener('change', loadPhotos);

    loadPhotos();
  </script>
</body>
</html>
