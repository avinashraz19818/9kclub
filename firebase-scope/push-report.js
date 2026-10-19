/* 1877 Firebase 推送送达/点击上报 —— Service Worker 内自签名上报模块。
 *
 * 为什么放在 SW:收到后台推送发生在 Service Worker,拿不到 app 的 axios / @arsaas/utils
 * 的 signData,也读不到 localStorage;而「app 关闭时的后台送达」正是本需求统计的主体,
 * 只有 SW 能可靠处理。故送达/点击上报全部在此自签名 + fetch。
 *
 * 依赖:同目录 spark-md5.min.js(必须先 importScripts),用同一份 MD5 保证签名与 app 端一致。
 * 用法:firebase-messaging-sw.js 里 importScripts 本文件后,调用全局 reportPush(action, data)。
 *
 * 规则(接口文档):
 *  - msgId = 载荷 data.messageId 转数字,非正数跳过
 *  - 载荷有 reportId 传 reportId,否则传 IndexedDB 里 write-once 的 token;两者皆无跳过
 *  - action=1(送达)先随机延迟 0~20s 再取时间戳发请求;action=2(点击)不延迟
 *  - 没用到的凭证字段不传(不补空串);失败一律吞掉,不影响通知流程
 */
(function (global) {
  'use strict';

  var REPORT_URL = '/api/Push/Report';
  // 与页面侧 pushReportCtx.ts 严格一致(同 DB/store/key/version),SW 才能读到页面写入的 token
  var DB_NAME = 'ar_push_report';
  var STORE = 'ctx';
  var KEY = 'report';
  var DB_VERSION = 1;

  /* ---- 签名:1:1 对齐 packages/utils/src/util/encrypt.ts ---- */
  function isArray(v) { return Array.isArray(v); }
  function isObject(v) { return v !== null && typeof v === 'object'; }

  function randomInt(n) {
    if (n <= 0) return -1;
    var limit = Math.pow(10, n);
    var value = Math.floor(Math.random() * limit);
    if (value < (limit / 10) && value !== 0) return randomInt(n);
    return value;
  }

  function encryptWithMD5(str) {
    return global.SparkMD5.hash(str).toString().toUpperCase().slice(0, 32);
  }

  function sortObjects(obj) {
    var sortedObj = {};
    var keys = Object.keys(obj).filter(function (key) {
      return !(isArray(obj[key]) || obj[key] === '');
    }).sort();
    keys.forEach(function (key) {
      if (obj[key] !== null && obj[key] !== '') {
        if (isObject(obj[key])) {
          sortedObj[key] = sortObjects(obj[key]);
        } else {
          sortedObj[key] = obj[key] === 0.0 ? 0.0 : obj[key];
        }
      }
    });
    return sortedObj;
  }

  function signData(data) {
    var white = ['signature', 'track', 'timestamp'];
    data['random'] = randomInt(12);
    var configData = JSON.parse(JSON.stringify(data));
    var keys = Object.keys(configData).filter(function (key) {
      return !isArray(configData[key]);
    });
    keys.sort();
    var sortedObject = {};
    keys.forEach(function (key) {
      if (configData[key] !== null && configData[key] !== '' && white.indexOf(key) === -1) {
        sortedObject[key] = configData[key] === 0.0 ? 0.0 : configData[key];
      }
    });
    var sortParams = sortObjects(sortedObject);
    data['signature'] = encryptWithMD5(JSON.stringify(sortParams));
    // 时间戳在此取——reportPush 保证延迟已发生,契合服务端时间偏差校验
    data['timestamp'] = Math.floor(Date.now() / 1000);
    return data;
  }

  /* ---- 读取页面写入的上报上下文 { token, userId, language } ---- */
  function readCtx() {
    return new Promise(function (resolve) {
      try {
        var req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = function () {
          try {
            if (!req.result.objectStoreNames.contains(STORE)) req.result.createObjectStore(STORE);
          } catch (e) { /* ignore */ }
        };
        req.onsuccess = function () {
          var db = req.result;
          try {
            var get = db.transaction(STORE, 'readonly').objectStore(STORE).get(KEY);
            get.onsuccess = function () { resolve(get.result || null); try { db.close(); } catch (e) {} };
            get.onerror = function () { resolve(null); try { db.close(); } catch (e) {} };
          } catch (e) { resolve(null); try { db.close(); } catch (e2) {} }
        };
        req.onerror = function () { resolve(null); };
      } catch (e) { resolve(null); }
    });
  }

  function delay(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  /**
   * @param {1|2} action 1=送达 2=点击
   * @param {object} data 推送 data 载荷(含 messageId、可能含 reportId)
   */
  async function reportPush(action, data) {
    try {
      data = data || {};
      console.log('[push-report] reportPush called', { action: action, data: data });
      var msgId = Number(data.messageId);
      if (!(msgId > 0)) { console.warn('[push-report] skip: msgId 非正数', data.messageId); return; } // 非数字/0 后端会拒

      var reportId = data.reportId;
      var ctx = await readCtx(); // { token, userId, language } | null
      var token = reportId ? undefined : (ctx && ctx.token);
      console.log('[push-report] 凭证判定', { reportId: reportId, hasToken: !!token, ctx: ctx });
      if (!reportId && !token) { console.warn('[push-report] skip: 无 reportId 且 IDB 无 token'); return; } // 无凭证跳过

      if (action === 1) await delay(Math.floor(Math.random() * 20001)); // 0~20s 削峰

      var body = { msgId: msgId, action: action, language: (ctx && ctx.language) || 'en' };
      if (reportId) body.reportId = reportId; else body.token = token; // 二选一,不补空串
      signData(body); // random + signature + timestamp(延迟后取)

      console.log('[push-report] 发送上报', REPORT_URL, body);
      var resp = await fetch(REPORT_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      console.log('[push-report] 上报响应', resp && resp.status);
    } catch (e) {
      // 统计用途,异常一律吞掉,不阻断通知流程
      console.warn('[push-report] 上报异常(已吞)', e);
    }
  }

  global.reportPush = reportPush;
})(self);
