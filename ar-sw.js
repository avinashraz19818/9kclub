/**
 * 单一 Root Service Worker
 * ============================
 * 合并自原 ar-sw.js(离线降级)+ 原 pwa-sw.js(PWA 缓存策略),解决两者
 * 同 root scope 注册时浏览器只保留最后一个的竞态。
 *
 * 职责分档:
 * - HTML 入口           → 不缓存,失败时返回兜底页(sw-page.js 提供)
 * - KV 配置 / *config.js → 网络优先 + 6s 超时回退缓存
 * - 带 hash 静态资产    → 永久 cache(文件名变化 = 新版本)
 * - 图片                → stale-while-revalidate
 * - 字体                → 永久 cache
 * - API / WS / HMR     → 不拦截,交浏览器默认
 *
 * CACHE_PREFIX 保留 'ar-pwa' 以兼容旧 pwa-sw.js 已写入的用户缓存
 * (升级后无须用户清缓存)。
 */
importScripts(
  './sw-utils.js',
  './sw-domain.js',
  './sw-page.js',
);

// ==================== 配置 ====================

const CACHE_PREFIX = 'ar-pwa';
const STATIC_CACHE = `${CACHE_PREFIX}-static-v1`;
const CONFIG_CACHE = `${CACHE_PREFIX}-config-v1`;
const ONLINE_PAGE_CACHE = 'online-page'; // sw-page.js 生成的兜底页缓存,沿用历史命名

// 带哈希的静态资源(如 index-a1b2c3d4.js)
const HASHED_ASSET_REGEX = /[.-][a-f0-9]{6,8}\.(js|css|woff2?)$/i;
// 图片
const IMAGE_REGEX = /\.(png|jpg|jpeg|gif|svg|webp|ico)$/i;
// 不进 SW 拦截、交浏览器默认的路径
const NO_INTERCEPT_PATTERNS = [
  '/api/',
  '/webapi/',
  '/Home/',
  '/socket',
  '/ws',
  'hot-update', // Vite HMR
];
// 无效路由占位(undefined / null 字面量被拼到 URL 时)
const INVALID_PATHS = ['/undefined', '/null', '/[object Object]'];

// ==================== 生命周期 ====================

self.addEventListener('install', () => {
  // 新 SW 装好立即进入 active
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // 清理与当前版本不匹配的旧 cache(防止 v1 → v2 时旧 cache 永久残留)
    try {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((k) => k.startsWith(CACHE_PREFIX) && k !== STATIC_CACHE && k !== CONFIG_CACHE)
          .map((k) => {
            console.log('[SW] removing old cache:', k);
            return caches.delete(k);
          })
      );
    } catch (e) {
      console.error('[SW] activate 清理旧 cache 失败:', e);
    }
    // 立即接管所有同 scope client,避免发版后旧 tab 仍跑旧 SW
    await self.clients.claim();
  })());
});

self.addEventListener('error', (event) => {
  console.error(
    '[SW] 捕获到错误:',
    event.message,
    '脚本:', event.filename,
    '行号:', event.lineno,
    '列号:', event.colno,
    '错误对象:', event.error
  );
});

// ==================== Fetch 拦截 ====================

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // 仅 GET
  if (request.method !== 'GET') return;
  // 仅 http(s)
  if (!url.protocol.startsWith('http')) return;
  // 仅同源(跨源交浏览器默认)
  if (url.origin !== self.location.origin) return;
  // NO_INTERCEPT(API / WS / HMR)
  if (NO_INTERCEPT_PATTERNS.some((p) => url.pathname.includes(p) || url.href.includes(p))) {
    return;
  }
  // 无效路径
  if (INVALID_PATHS.includes(url.pathname)) {
    console.warn('[SW] 忽略无效路径请求:', url.pathname);
    return;
  }

  // HTML 导航请求:不缓存,失败时返回兜底页
  if (request.mode === 'navigate' || request.destination === 'document') {
    event.respondWith(handleNavigation(request));
    return;
  }
  // KV 配置:网络优先 + 缓存兜底
  if (url.pathname.includes('/kv/') || url.pathname.endsWith('config.js')) {
    event.respondWith(networkFirstWithFallback(request, CONFIG_CACHE));
    return;
  }
  // 带 hash 静态资源:永久缓存
  if (HASHED_ASSET_REGEX.test(url.pathname)) {
    event.respondWith(cacheFirstForever(request, STATIC_CACHE));
    return;
  }
  // 图片:stale-while-revalidate
  if (IMAGE_REGEX.test(url.pathname) || request.destination === 'image') {
    event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
    return;
  }
  // 字体:永久缓存
  if (request.destination === 'font') {
    event.respondWith(cacheFirstForever(request, STATIC_CACHE));
    return;
  }
});

// ==================== 缓存策略 ====================

/**
 * 导航请求:走网络;失败 → 兜底页(由 sw-page.js 的 createDynamicOnlinePage 生成)。
 */
async function handleNavigation(request) {
  try {
    const response = await fetch(request);
    if (response.ok) return response;
    console.warn('[SW] navigation 非 200,fallback online 页');
  } catch (_e) {
    // 网络失败,落兜底
  }

  // 离线 → 简化提示(self.navigator 在 SW 里可达,但 onLine 不绝对可靠,仅作弱信号)
  if (self.navigator && !self.navigator.onLine) {
    return new Response(
      '<h1>navigator is offLine, Please check the device network</h1>',
      { status: 503, headers: { 'Content-Type': 'text/html' } }
    );
  }

  // 在线但请求失败 → 动态在线降级页
  try {
    const htmlContent = createDynamicOnlinePage(buildStringMap());
    const cache = await caches.open(ONLINE_PAGE_CACHE);
    try {
      await cache.put('sw-page.html', htmlContent.clone());
    } catch (cacheErr) {
      console.error('[SW] online 兜底页缓存失败:', cacheErr);
    }
    const cached = await cache.match('sw-page.html');
    return cached || htmlContent;
  } catch (e) {
    console.error('[SW] online 兜底页生成失败:', e);
    return new Response(
      '<h1>Request failed and offline fallback unavailable</h1>',
      { status: 503, headers: { 'Content-Type': 'text/html' } }
    );
  }
}

async function cacheFirstForever(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    console.error('[SW] cacheFirstForever fetch 失败:', request.url);
    throw error;
  }
}

async function networkFirstWithFallback(request, cacheName) {
  const cache = await caches.open(cacheName);
  try {
    const response = await fetchWithTimeout(request, 6000);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    console.warn('[SW] networkFirst 网络失败,尝试缓存:', request.url);
    const cached = await cache.match(request);
    if (cached) return cached;
    throw error;
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  // 后台更新
  const fetchPromise = fetch(request)
    .then((response) => {
      if (response.ok) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);

  return cached || fetchPromise;
}

function fetchWithTimeout(request, timeout) {
  return Promise.race([
    fetch(request),
    new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Timeout')), timeout)
    ),
  ]);
}

// ==================== 消息通信 ====================

self.addEventListener('message', (event) => {
  const { type } = event.data || {};

  if (type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (type === 'CLEAR_ALL_CACHE') {
    caches.keys().then((keys) => {
      keys
        .filter((k) => k.startsWith(CACHE_PREFIX) || k === ONLINE_PAGE_CACHE)
        .forEach((k) => caches.delete(k));
    });
  }
});
