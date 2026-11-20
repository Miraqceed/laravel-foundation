/**
 * パッケージの保守の道具：固定電話の番号を区切る市外局番の表（resources/japan/area-codes.json）を、
 * 総務省の「電気通信番号指定状況」から作り直す。
 *
 * **保守の人の手元だけで動かす。** CI でも配布物でも動かさない（tools/ は .gitattributes の export-ignore で配布物に入らない）。
 * 試しもこの道具を呼ばない（表の形の試しは、作った area-codes.json を読む）。市外局番が変わったら（まれ）作り直して PR にする。
 *
 *   node tools/make-area-codes.mjs
 *
 * 依存を足さない（composer の依存にも package.json にも入れない）。.xls（古い Excel の形）を読むため、動かすたびに
 * SheetJS の決まった版の tarball を取り、**決め打ちの SHA-512（integrity）を確かめてから**一時の所に展開して読み込む。
 * 違えば止まる（URL が同じでも中身が替われば、知らないコードを動かさない）。版を上げるときは、SHEETJS の版と integrity を
 * 一緒に直す PR にする。npm の xlsx（0.18.5 が最後）は使わない（知られた脆弱性がある）。
 *
 * 固定電話の番号は「0＋市外局番（1〜4桁）＋市内局番（1〜4桁）＋加入者番号（4桁）」で、0 から市内局番までの6桁（番号区画の「番号」）ごとに
 * 市外局番の桁が決まる（04-29xx と 042-6xx のように、先頭が同じでも6桁で変わる）。総務省はこの6桁ごとの指定状況を、市外局番の先頭の
 * 数字ごとの Excel で公開している。使用中・未使用の両方を読み、同じ桁にまとまる前方一致の表に縮める。
 *
 * 出典：総務省のホームページのコンテンツは「公共データ利用規約（第1.0版）」（CC BY 4.0 互換）による。表の source に、
 * 出典と「加工して作成」を書く（加工したものを国が作ったかのように見せない）。
 *
 * 取るのは決まった https の道だけ（総務省の www.soumu.go.jp と SheetJS の配布元）。リダイレクトに従わず、時間と大きさの上限つき。
 */
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gunzipSync } from 'node:zlib';

const SHEETJS = {
  url: 'https://cdn.sheetjs.com/xlsx-0.20.3/xlsx-0.20.3.tgz',
  integrity: 'sha512-oLDq3jw7AcLqKWH2AhCpVTZl8mf6X2YReP+Neh0SJUzV/BdZYjth94tG5toiMB1PPrYtxOCfaoUCkvtuH+3AJA==',
};
const PAGE = 'https://www.soumu.go.jp/main_sosiki/joho_tsusin/top/tel_number/number_shitei.html';
const SOUMU = 'https://www.soumu.go.jp/';
const OUT = fileURLToPath(new URL('../resources/japan/area-codes.json', import.meta.url));

const TIMEOUT_MS = 60_000;
const MAX_PAGE_BYTES = 2 * 1024 * 1024;
const MAX_XLS_BYTES = 10 * 1024 * 1024;
const MAX_TGZ_BYTES = 20 * 1024 * 1024;
const MAX_TAR_BYTES = 100 * 1024 * 1024;

/** 決まった https の道を、リダイレクトに従わず、時間と大きさの上限つきで取る */
async function get(url, allowedPrefix, maxBytes) {
  if (!url.startsWith(allowedPrefix)) throw new Error(`決まった道の外です：${url}`);
  const response = await fetch(url, {
    redirect: 'error',
    signal: AbortSignal.timeout(TIMEOUT_MS),
    headers: { 'user-agent': 'miraqceed/laravel-foundation' },
  });
  if (response.status !== 200) throw new Error(`${url} を取れません（HTTP ${response.status}）`);
  const declared = Number(response.headers.get('content-length') ?? '0');
  if (declared > maxBytes) throw new Error(`${url} が大きすぎます（${declared} バイト）`);
  const chunks = [];
  let length = 0;
  for await (const chunk of response.body) {
    length += chunk.length;
    if (length > maxBytes) throw new Error(`${url} が大きすぎます（上限 ${maxBytes} バイトを超えた）`);
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

/** tarball を取り、integrity を確かめてから、一時の所に展開して読み込む */
async function loadSheetJs(directory) {
  const tgz = await get(SHEETJS.url, 'https://cdn.sheetjs.com/', MAX_TGZ_BYTES);
  const integrity = `sha512-${createHash('sha512').update(tgz).digest('base64')}`;
  if (integrity !== SHEETJS.integrity) {
    throw new Error(`SheetJS の tarball の integrity が決め打ちの値と違います（${integrity}）。読み込みません`);
  }
  console.log(`SheetJS の integrity が決め打ちの値と合いました（${SHEETJS.url}・${tgz.length} バイト）`);

  const tar = gunzipSync(tgz, { maxOutputLength: MAX_TAR_BYTES });
  const root = resolve(directory);
  for (let at = 0; at + 512 <= tar.length; ) {
    const header = tar.subarray(at, at + 512);
    if (header.every((b) => b === 0)) break;
    const text = (from, to) => header.subarray(from, to).toString('utf8').replace(/\0.*$/s, '');
    const name = [text(345, 500), text(0, 100)].filter(Boolean).join('/');
    const size = parseInt(text(124, 136).trim() || '0', 8);
    const type = text(156, 157) || '0';
    const body = tar.subarray(at + 512, at + 512 + size);
    at += 512 + Math.ceil(size / 512) * 512;
    if (type !== '0') continue; // 普通のファイルだけ（pax の見出し・ディレクトリ・リンクは書かない）
    const target = resolve(root, name);
    if (!target.startsWith(root + sep)) throw new Error(`tarball の中の名前が一時の所の外を指します：${name}`);
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, body);
  }
  return createRequire(join(root, 'package', 'package.json'))('./xlsx.js');
}

const work = mkdtempSync(join(tmpdir(), 'make-area-codes-'));
try {
  const XLSX = await loadSheetJs(work);

  const html = new TextDecoder('shift_jis').decode(await get(PAGE, SOUMU, MAX_PAGE_BYTES));
  // 「1から始まる市外局番[PDF形式、Excel形式]」… の Excel の版
  const links = [...html.matchAll(/([1-9])から始まる市外局番[\s\S]*?href="([^"]+\.xls)"/g)].map((m) => new URL(m[2], PAGE).href);
  if (links.length !== 9) throw new Error(`市外局番の Excel が9つ見つかりません（${links.length}）`);

  const leaves = new Map();
  const asOf = new Set();
  for (const url of links) {
    const book = XLSX.read(await get(url, SOUMU, MAX_XLS_BYTES));
    const rows = XLSX.utils.sheet_to_json(book.Sheets[book.SheetNames[0]], { header: 1 });
    asOf.add(String(rows[0].find(Boolean)).replace(/[()（）]/g, ''));
    for (const row of rows.slice(2)) {
      const [, number, area, local] = row.map((v) => (v == null ? '' : String(v)));
      if (!number) continue;
      if (!/^0\d{5}$/.test(number) || number !== area + local) throw new Error(`知らない形の行です：${JSON.stringify(row)}`);
      if (leaves.has(number) && leaves.get(number) !== area.length) throw new Error(`市外局番の桁が食い違います：${number}`);
      leaves.set(number, area.length);
    }
  }

  // 6桁ごとの市外局番の桁（0 を含めて2〜5）を、同じ桁にまとまる前方一致の表に縮める
  const prefixes = {};
  const walk = (prefix) => {
    const lengths = new Set([...leaves].filter(([n]) => n.startsWith(prefix)).map(([, l]) => l));
    if (lengths.size === 0) return;
    if (lengths.size === 1) {
      prefixes[prefix] = [...lengths][0];
      return;
    }
    for (let d = 0; d <= 9; d++) walk(prefix + d);
  };
  walk('0');

  writeFileSync(OUT, `${JSON.stringify({
    source: `総務省ホームページ「電気通信番号指定状況」の固定電話の電話番号（${PAGE}）を加工して作成（6桁の番号区画ごとの市外局番の桁を、前方一致の表に縮めた。tools/make-area-codes.mjs）`,
    asOf: [...asOf].sort(),
    prefixes,
  }, null, 2)}\n`);
  console.log(`${leaves.size} の番号区画から ${Object.keys(prefixes).length} 行の表を作りました：${OUT}`);
} finally {
  rmSync(work, { recursive: true, force: true });
}
