// Firebase JS SDK 版本(走 gstatic CDN,但 gstatic 要求完整 X.Y.Z,不接受 floating)
// 升级时只需改这一处。Changelog: https://firebase.google.com/support/release-notes/js
// 详见 apps/y1/public/VENDORED.md
const FIREBASE_VERSION = '10.8.1';
importScripts(`https://www.gstatic.com/firebasejs/${FIREBASE_VERSION}/firebase-app-compat.js`);
importScripts(`https://www.gstatic.com/firebasejs/${FIREBASE_VERSION}/firebase-messaging-compat.js`);
importScripts("./webapi/kv/firebaseConfig.js");
// 1877 推送送达/点击上报:自签名 + fetch(依赖 spark-md5,顺序不可颠倒),暴露全局 reportPush
importScripts("./firebase-scope/spark-md5.min.js");
importScripts("./firebase-scope/push-report.js");

let messaging = null;
let isFirebaseInitialized = false;
let firebaseConfigCache = null;
let firebaseInitPromise = null;


/* ------------------------------
 * 🧩 生命周期：install / activate
 * ------------------------------ */
self.addEventListener('install', (event) => {
  // 立即进入 waiting -> activate
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // 让新 SW 立即接管页面
    await self.clients.claim();

    // 🔥 关键：activate 预热（强制读取 IDB + 初始化 Firebase）
    try {
      console.log('[SW] activate → preload firebase');
      await ensureFirebaseReady();
      console.log('[SW] firebase preloaded');
    } catch (e) {
      console.error('[SW] preload firebase failed:', e);
      // 不要 throw，activate 仍然要完成；后续 push 还能重试
    }
  })());
});

async function ensureFirebaseReady() {
  if (isFirebaseInitialized) return true;

  if (!firebaseInitPromise) {
    firebaseInitPromise = (async () => {
      await initFirebase(); // 失败必须 throw
      return true;
    })().catch((e) => {
      // 失败允许重试
      firebaseInitPromise = null;
      throw e;
    });
  }
  return firebaseInitPromise;
}

/* ------------------------------
 * 🧭 事件映射表
 * ------------------------------ */
const eventNameMap = {
  notification_receive: 'NOTIFICATION_RECEIVE',
  notification_display: 'NOTIFICATION_DISPLAY',
  notification_click: 'NOTIFICATION_CLICK',
  notification_dismiss: 'NOTIFICATION_DISMISS',
  notification_open: 'NOTIFICATION_OPEN',
  notification_engagement: 'NOTIFICATION_ENGAGEMENT', // 保留未来扩展
};


/**
 * @param {string} type - 事件类型
 * @param {object} data - 附带数据
 * @param {object} [options]
 * @param {boolean} [options.focusExisting=true] - 是否聚焦已有页面
 * @param {boolean} [options.sameOriginOnly=true] - 仅发送给同源页面
 * @param {boolean} [options.openIfNotFound=false] - 若无页面是否新开
 * @param {string} [options.openUrl='/'] - 新开页面的默认URL
 */
async function sendToClients(type, data, options = {}) {
  const {
    focusExisting = true,
    sameOriginOnly = true,
    openIfNotFound = false,
    openUrl = '/'
  } = options;

  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  try {
    for (const client of clients) {
      // 严格按 URL.origin 比对,不要用 substring(避免 https://evil.com/?x=https://our.com/ 被误判为同源)
      if (sameOriginOnly) {
        try {
          if (new URL(client.url).origin !== self.location.origin) continue;
        } catch (_e) {
          continue; // 异常 URL 直接跳过
        }
      }
      client.postMessage({ type, data });
    }
  } catch (error) {
    console.error('发送消息到客户端失败:', error);
  }
  return true;
}


// 接口如果拿不到可以通过h5存入indexDB再取
const initFirebase = () => {
  if (isFirebaseInitialized && firebaseConfigCache) {
    return;
  }
  firebase.initializeApp(_FIREBASE_CONFIG_);
  firebaseConfigCache = _FIREBASE_CONFIG_;
  isFirebaseInitialized = true;

  messaging = firebase.messaging();
  messaging.onBackgroundMessage(async (payload) => {
   return receiveBackgroundMessage(payload);
  });
}

/* ------------------------------
 * 📨 后台消息监听（Service Worker 收到推送）
 * ------------------------------ */

const receiveBackgroundMessage = async (payload) => {
console.log('后台接受', payload);

  // 1️⃣ 通知接收埋点
  await sendToClients(eventNameMap.notification_receive, payload.data);

  const { title, body, image, targetLink, openPage } = payload.data || payload.notification || {};
  const data = payload.data || payload.notification || {};

  // 3️⃣ 显示通知
  await self.registration.showNotification(title || 'Notify', {
    body: body || '',
    image: image || '/logo.png',
    icon: image || '/logo.png',
    data,
    tag: data.messageId || Date.now().toString(), // 防止重复通知
  });

  // 4️⃣ 通知展示埋点
  await sendToClients(eventNameMap.notification_display, payload.data);

  // 5️⃣ 送达上报(action=1)。通知已展示,延迟只作用于上报;整体在 onBackgroundMessage
  //    返回的 promise 内,SW 靠 push 事件 waitUntil 存活至上报完成(~20s)。失败已在内部吞掉。
  await reportPush(1, data);
}


/* ------------------------------
 * 📤 初始化
 * ------------------------------ */

initFirebase()


/* ------------------------------
 * 📤 前台消息触发通知（页面调用）
 * ------------------------------ */

// 监听来自页面的消息
self.addEventListener('message', (event) => {
  console.log('Service Worker 收到消息:', event.data);
  // 1877 前台消息(app 打开时 FCM 走页面 onMessage,不触发 SW 的 receiveBackgroundMessage),
  // 由页面转发到 SW 统一做送达上报(action=1),复用同一份 IDB token + 签名逻辑。
  if (event.data?.type === 'REPORT_DELIVERY') {
    event.waitUntil(reportPush(1, event.data.payload || {}));
    return;
  }
  if (event.data?.type === 'FIREBASE_SHOW_NOTIFICATION') {
    const { title, body, image, openPage, targetLink, messageId } = event.data.payload || {};

    console.log('前台消息触发通知',  event.data.payload);

    self.registration.showNotification(title || "Notify", {
      body,
      image: image || '/logo.png',
      icon: image || '/logo.png',
      data: event.data.payload
    });
  }
});

/* ------------------------------
 * ❌ 通知关闭事件
 * ------------------------------ */
self.addEventListener('notificationclose', (event) => {
  sendToClients(eventNameMap.notification_dismiss, { ...event.notification?.data });
});

/* ------------------------------
 * ✅ 通知点击事件（统计 + 打开链接）
 * ------------------------------ */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const messageData = event.notification?.data;

  // 把 messageData 里的原始字段拼成 query string,供新开窗口落地页带参
  const buildTargetUrl = () => {
    const url = new URL(self.location.origin);
    if (messageData && typeof messageData === 'object') {
      Object.entries(messageData).forEach(([key, value]) => {
        // 只带原始值,跳过 undefined/null 以及嵌套 object/array
        if (value !== undefined && value !== null && typeof value !== 'object') {
          url.searchParams.set(key, value);
        }
      });
    }
    return url.toString();
  };

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    // 严格按 origin 比对,避免 substring 误判
    const sameOriginClients = [];
    for (const client of allClients) {
      try {
        if (new URL(client.url).origin === self.location.origin) {
          sameOriginClients.push(client);
        }
      } catch (_e) { /* 异常 URL 跳过 */ }
    }

    if (sameOriginClients.length > 0) {
      // 已有同源页面:聚焦第一个 + postMessage,**不再开新窗**(修双开窗 bug)
      const target = sameOriginClients[0];
      try {
        if ('focus' in target) await target.focus();
        target.postMessage({ type: eventNameMap.notification_click, data: { ...messageData } });
      } catch (err) {
        console.error('focus/postMessage 失败:', err);
      }
    } else if (self.clients.openWindow) {
      // 没有同源页面:开**一个**新窗口落地(且仅一次)
      try {
        await self.clients.openWindow(buildTargetUrl());
      } catch (err) {
        console.error('openWindow 失败:', err);
      }
    }

    // notification_open 埋点,与两种分支独立
    await sendToClients(eventNameMap.notification_open, { ...messageData });

    // 点击上报(action=2,不延迟)。失败已在内部吞掉,不影响跳转/开窗。
    await reportPush(2, messageData || {});
  })());
});